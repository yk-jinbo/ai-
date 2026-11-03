<?php

namespace Tests\Feature\Auth;

use App\Caches\CacheManager;
use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 验证身份确认后的长令牌能够保存、鉴权和退出，并兼容已缓存的短令牌。
 * 上游 HTTP 和 Redis 均使用内存替身，不连接实际服务。
 */
class LoginConfirmationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{ttl: int, payload: string}> */
    private array $tokens = [];

    /**
     * 配置隔离缓存和伪造 Redis，并注册仅供测试读取当前用户的受保护路由。
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'login-confirmation-test-only-key');
        config()->set('services.member_auth.base_url', 'https://identity.example.test');
        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('setex')->andReturnUsing(function (string $key, int $ttl, string $payload): bool {
            $this->tokens[$key] = ['ttl' => $ttl, 'payload' => $payload];

            return true;
        });
        $redis->shouldReceive('get')->andReturnUsing(fn (string $key): ?string => $this->tokens[$key]['payload'] ?? null);
        $redis->shouldReceive('del')->andReturnUsing(function (string $key): int {
            $deleted = isset($this->tokens[$key]);
            unset($this->tokens[$key]);

            return (int) $deleted;
        });
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($redis);
        $this->app->instance(Factory::class, $factory);
        Http::preventStrayRequests();
        Route::middleware('member.auth')->get('/api/test/member', function (Request $request): JsonResponse {
            return ResponseHelper::success($request->attributes->get('member_auth_user'));
        });
    }

    /**
     * 提供覆盖缓存标识长度边界的令牌长度，长令牌模拟上游 JWT。
     *
     * @return array<string, array{int}> 场景与令牌字节长度
     */
    public static function tokenLengths(): array
    {
        return [
            'short' => [64],
            'identifier limit' => [256],
            'over identifier limit' => [257],
            'long access token' => [1024],
        ];
    }

    /**
     * 确认登录后使用完整令牌鉴权，错误令牌和退出后的原令牌均被拒绝。
     *
     * @param  int  $tokenLength  伪造上游令牌的字节长度
     */
    #[DataProvider('tokenLengths')]
    public function test_confirmed_token_authenticates_and_can_be_revoked(int $tokenLength): void
    {
        $token = str_repeat('x', $tokenLength);
        Http::fake(['https://identity.example.test/api-iam/api/v1/member/login/confirm' => Http::response([
            'code' => 'OK',
            'msg' => 'ok',
            'data' => ['accessToken' => $token, 'tokenType' => 'Bearer', 'expiresIn' => 1800, 'user' => ['id' => 7]],
        ])]);

        $response = $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'test-login-ticket',
            'member_type' => 'NORMAL',
            'agreement_consent' => $this->agreementConsent(),
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['code', 'message', 'data' => ['token', 'token_type', 'expires_in', 'member_type', 'user']]);
        $response->assertJson(['code' => 0, 'data' => ['token' => $token, 'token_type' => 'Bearer', 'expires_in' => 1800, 'member_type' => 'NORMAL']]);
        $this->assertCount(1, $this->tokens);
        foreach ($this->tokens as $entry) {
            $this->assertSame(1800, $entry['ttl']);
        }
        $this->assertStringNotContainsString($token, array_key_first($this->tokens));

        $this->withToken($token)->getJson('/api/test/member')->assertStatus(200)->assertJson(['code' => 0, 'data' => ['id' => 7, 'member_type' => 'NORMAL']]);
        $this->withToken($token.'different')->getJson('/api/test/member')->assertStatus(401);
        $this->withToken('sha256:'.hash('sha256', $token))->getJson('/api/test/member')->assertStatus(401);
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200)->assertJson(['code' => 0]);
        $this->assertSame([], $this->tokens);
        $this->withToken($token)->getJson('/api/test/member')->assertStatus(401);
        Http::assertSentCount(1);
    }

    /**
     * 修复前已保存的短令牌沿用原缓存标识，可以继续鉴权和退出。
     */
    public function test_previously_stored_short_tokens_remain_valid(): void
    {
        $token = 'legacy-short-access-token';
        $scope = $this->app->make(CacheManager::class)->scope('auth-tokens');
        $scope->connection()->setex($scope->key($token), 1800, json_encode([
            'user' => ['id' => 9, 'member_type' => 'NORMAL'],
        ], JSON_THROW_ON_ERROR));

        $this->withToken($token)->getJson('/api/test/member')->assertStatus(200)->assertJson(['code' => 0, 'data' => ['id' => 9]]);
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200)->assertJson(['code' => 0]);
        $this->assertSame([], $this->tokens);
        Http::assertNothingSent();
    }

    /**
     * 身份确认响应中的手机号进入本地用户缓存，业务接口可通过 currentUser() 读取。
     */
    public function test_confirm_response_mobile_is_available_from_current_user(): void
    {
        $token = str_repeat('t', 64);
        Http::fake([
            'https://identity.example.test/api-iam/api/v1/member/login/sms/prepare' => Http::response([
                'code' => 'OK',
                'msg' => 'ok',
                'data' => ['ticket' => 'sms-login-ticket', 'memberTypes' => ['NORMAL']],
            ]),
            'https://identity.example.test/api-iam/api/v1/member/login/confirm' => Http::response([
                'code' => 'OK',
                'msg' => 'ok',
                'data' => ['accessToken' => $token, 'expiresIn' => 1800, 'user' => ['id' => 8, 'mobile' => '13900001111']],
            ]),
        ]);

        $this->postJson('/api/auth/login/sms', [
            'mobile' => '13812345678',
            'code' => '123456',
        ])->assertStatus(200)->assertJson(['code' => 0, 'data' => ['ticket' => 'sms-login-ticket']]);

        $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'sms-login-ticket',
            'member_type' => 'NORMAL',
            'agreement_consent' => $this->agreementConsent(),
        ])->assertStatus(200)->assertJson([
            'code' => 0,
            'data' => ['user' => ['id' => 8, 'mobile' => '13900001111']],
        ]);

        $this->withToken($token)->getJson('/api/test/member')->assertStatus(200)->assertJson([
            'code' => 0,
            'data' => ['id' => 8, 'mobile' => '13900001111'],
        ]);
    }

    /**
     * 未接受协议时本项目不阻止身份确认，并将 false 原样转发给上游。
     */
    public function test_confirm_login_allows_unaccepted_agreement_value(): void
    {
        Http::fake([
            'https://identity.example.test/api-iam/api/v1/member/login/confirm' => Http::response([
                'code' => 'OK',
                'msg' => 'ok',
                'data' => ['accessToken' => 'agreement-test-token', 'expiresIn' => 1800],
            ]),
        ]);

        $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'test-login-ticket',
            'member_type' => 'NORMAL',
            'agreement_consent' => ['accepted' => false],
        ])->assertStatus(200)->assertJson(['code' => 0]);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), 'member/login/confirm')
            && $request['agreementConsent'] === ['accepted' => false]
            && ! isset($request['agreementAccepted'])
        );
    }

    /**
     * 用户已确认的摘要和全部版本直接转发，版本 ID 保持字符串且不额外重新查询协议。
     */
    public function test_confirm_login_forwards_complete_consent_and_preserves_version_ids(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'OK',
            'data' => ['accessToken' => 'complete-consent-token', 'user' => ['mobile' => '13900001111']],
        ])]);

        $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'confirmed-policy-ticket',
            'member_type' => 'NORMAL',
            'agreement_consent' => $this->agreementConsent(),
        ])->assertStatus(200)->assertJson(['code' => 0]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->data() === [
            'ticket' => 'confirmed-policy-ticket',
            'memberType' => 'NORMAL',
            'agreementConsent' => [
                'accepted' => true,
                'policyHash' => 'policy-reviewed-by-user',
                'documents' => [
                    ['documentCode' => 'NORMAL_USER_AGREEMENT', 'versionId' => '9007199254740993'],
                    ['documentCode' => 'NORMAL_PRIVACY_POLICY', 'versionId' => '102'],
                ],
            ],
        ]);
        Http::assertSentCount(1);
    }

    /**
     * 协议校验关闭的上游可接收未提供同意对象的请求，本项目不补造用户同意。
     */
    public function test_confirm_login_without_consent_does_not_add_acceptance(): void
    {
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => ['accessToken' => 'no-consent-token']])]);

        $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'no-consent-ticket',
            'member_type' => 'NORMAL',
        ])->assertStatus(200)->assertJson(['code' => 0]);

        Http::assertSent(fn (ClientRequest $request): bool => ! isset($request['agreementConsent']) && ! isset($request['agreementAccepted']));
    }

    /** @return array<string, mixed> 用户阅读确认的协议摘要与完整版本集合 */
    private function agreementConsent(): array
    {
        return [
            'accepted' => true,
            'policy_hash' => 'policy-reviewed-by-user',
            'documents' => [
                ['document_code' => 'NORMAL_USER_AGREEMENT', 'version_id' => '9007199254740993'],
                ['document_code' => 'NORMAL_PRIVACY_POLICY', 'version_id' => '102'],
            ],
        ];
    }
}

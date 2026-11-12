<?php

namespace Tests\Feature\Member;

use App\Caches\CacheManager;
use App\Enums\ApiCode;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 会员身份及关联组织接口契约测试
 * 验证 Token 身份参数、第三方地址和错误响应
 */
class MemberTest extends TestCase
{
    private const TOKEN = 'member-identities-access-token';

    private const UPSTREAM_URL = 'https://assets.example.test/api/v1/computing/query/members/identities';

    /** @var array<string, string> */
    private array $redisValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'member-identities-test-key');
        config()->set('services.member_identities.url', self::UPSTREAM_URL);

        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('get')->andReturnUsing(fn (string $key): ?string => $this->redisValues[$key] ?? null);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($redis);
        $this->app->instance(Factory::class, $factory);

        $scope = $this->app->make(CacheManager::class)->scope('auth-tokens');
        $this->redisValues[$scope->key(self::TOKEN)] = json_encode([
            'user' => ['mobile' => '13900001111', 'member_type' => 'NORMAL'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Http::preventStrayRequests();
    }

    public function test_returns_member_identities_from_upstream(): void
    {
        $data = [
            'identities' => [
                ['memberType' => 'NORMAL', 'organizations' => []],
            ],
        ];
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => $data,
            ]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/get-identities');

        $response->assertOk()->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '获取会员身份及关联组织成功',
            'data' => $data,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === [
                'token' => self::TOKEN,
                'mobile' => '13900001111',
                'memberType' => 'NORMAL',
            ]);
    }

    public function test_rejects_missing_token_without_calling_upstream(): void
    {
        Http::fake();

        $response = $this->postJson('/api/member/get-identities');

        $response->assertUnauthorized()->assertJsonPath('code', ApiCode::UNAUTHORIZED->value);
        Http::assertNothingSent();
    }

    public function test_returns_upstream_business_error(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0004',
                'message' => '会员身份不存在',
                'data' => null,
            ], 400),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/get-identities');

        $response->assertStatus(400)->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '会员身份不存在',
            'data' => null,
        ]);
    }
}

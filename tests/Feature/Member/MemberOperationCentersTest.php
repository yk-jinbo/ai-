<?php

namespace Tests\Feature\Member;

use App\Caches\CacheManager;
use App\Enums\ApiCode;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 下属运营中心考核接口契约测试
 * 验证会员身份、分公司条件、可选日期、默认分页和错误响应
 */
class MemberOperationCentersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'member-operation-centers-token';

    private const UPSTREAM_URL = 'https://assets.example.test/api/v1/computing/query/organizations/operation-centers';

    /** @var array<string, string> */
    private array $redisValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'member-operation-centers-test-key');
        config()->set('services.member_operation_centers.url', self::UPSTREAM_URL);

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

    /** @return void */
    public function test_returns_operation_centers_and_sends_all_parameters(): void
    {
        $data = ['items' => [['operationCenterId' => '31001']], 'total' => 1, 'pageNo' => 1, 'pageSize' => 20];
        Http::fake([
            self::UPSTREAM_URL => Http::response(['code' => '0000', 'message' => '成功', 'data' => $data]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/operation-centers', [
            'asOfDate' => '2026-10-27',
            'branchCompanyId' => '30002',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);

        $response->assertOk()->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '获取下属运营中心考核成功',
            'data' => $data,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === [
                'token' => self::TOKEN,
                'mobile' => '13900001111',
                'memberType' => 'NORMAL',
                'branchCompanyId' => '30002',
                'pageNo' => 1,
                'pageSize' => 20,
                'asOfDate' => '2026-10-27',
            ]);
    }

    /** @return void */
    public function test_omits_optional_date_and_uses_default_pagination(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response(['code' => '0000', 'message' => '成功', 'data' => []]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/operation-centers', [
            'branchCompanyId' => '30002',
        ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13900001111',
            'memberType' => 'NORMAL',
            'branchCompanyId' => '30002',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);
    }

    /** @return void */
    public function test_rejects_missing_branch_company_id(): void
    {
        Http::fake();

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/operation-centers');

        $response->assertUnprocessable()
            ->assertJsonPath('code', ApiCode::INVALID_PARAMS->value)
            ->assertJsonStructure(['data' => ['branchCompanyId']]);
        Http::assertNothingSent();
    }

    /** @return void */
    public function test_rejects_invalid_optional_parameters(): void
    {
        Http::fake();

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/operation-centers', [
            'branchCompanyId' => '30002',
            'asOfDate' => '2026/10/27',
            'pageNo' => 0,
            'pageSize' => 101,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', ApiCode::INVALID_PARAMS->value)
            ->assertJsonStructure(['data' => ['asOfDate', 'pageNo', 'pageSize']]);
        Http::assertNothingSent();
    }

    /** @return void */
    public function test_rejects_missing_token_without_calling_upstream(): void
    {
        Http::fake();

        $response = $this->postJson('/api/member/operation-centers', [
            'branchCompanyId' => '30002',
        ]);

        $response->assertUnauthorized()->assertJsonPath('code', ApiCode::UNAUTHORIZED->value);
        Http::assertNothingSent();
    }

    /** @return void */
    public function test_returns_upstream_business_error(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0004',
                'message' => '分公司不存在',
                'data' => null,
            ], 400),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/member/operation-centers', [
            'branchCompanyId' => '30002',
        ]);

        $response->assertStatus(400)->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '分公司不存在',
            'data' => null,
        ]);
    }
}

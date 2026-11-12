<?php

namespace Tests\Feature\Agent;

use App\Caches\CacheManager;
use App\Enums\ApiCode;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 代理收益接口契约测试
 * 验证会员身份透传、筛选参数、参数校验和第三方错误响应
 */
class AgentTest extends TestCase
{
    private const TOKEN = 'agent-earnings-access-token';

    private const UPSTREAM_URL = 'https://assets.example.test/api/v1/computing/query/agents/earnings';

    /** @var array<string, string> */
    private array $redisValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'agent-earnings-test-key');
        config()->set('services.agent_earnings.url', self::UPSTREAM_URL);

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

    public function test_returns_agent_earnings_and_sends_filters_to_upstream(): void
    {
        $data = ['items' => [['agentOrderNo' => 'AG202610001']], 'total' => 1, 'pageNo' => 1, 'pageSize' => 20];
        Http::fake([self::UPSTREAM_URL => Http::response(['code' => '0000', 'message' => '成功', 'data' => $data])]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/agent/get-earnings', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'pageNo' => 1,
            'pageSize' => 20,
            'agentType' => 'CITY',
            'regionCode' => '330100',
            'agentOrderNo' => 'AG202610001',
        ]);

        $response->assertOk()->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '获取代理收益明细成功',
            'data' => $data,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === [
                'token' => self::TOKEN,
                'mobile' => '13900001111',
                'memberType' => 'NORMAL',
                'fromDate' => '2026-10-01',
                'toDate' => '2026-10-27',
                'pageNo' => 1,
                'pageSize' => 20,
                'agentType' => 'CITY',
                'regionCode' => '330100',
                'agentOrderNo' => 'AG202610001',
            ]);
    }

    public function test_omits_null_dates_and_filters_and_uses_default_pagination(): void
    {
        Http::fake([self::UPSTREAM_URL => Http::response(['code' => '0000', 'message' => '成功', 'data' => []])]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/agent/get-earnings', [
            'fromDate' => null,
            'toDate' => null,
            'agentType' => null,
            'regionCode' => null,
            'agentOrderNo' => null,
        ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13900001111',
            'memberType' => 'NORMAL',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);
    }

    public function test_rejects_invalid_agent_type(): void
    {
        Http::fake();

        $response = $this->withToken(self::TOKEN)->postJson('/api/agent/get-earnings', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'agentType' => 'STREET',
        ]);

        $response->assertUnprocessable()->assertJsonPath('code', ApiCode::INVALID_PARAMS->value);
        Http::assertNothingSent();
    }

    public function test_passes_time_values_without_local_time_validation(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => [],
            ]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/agent/get-earnings', [
            'fromDate' => '2026/10/27 18:30:00',
            'toDate' => '2026/10/01 09:00:00',
        ]);

        $response->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13900001111',
            'memberType' => 'NORMAL',
            'fromDate' => '2026/10/27 18:30:00',
            'toDate' => '2026/10/01 09:00:00',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);
    }

    public function test_rejects_missing_token_without_calling_upstream(): void
    {
        Http::fake();

        $response = $this->postJson('/api/agent/get-earnings', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
        ]);

        $response->assertUnauthorized()->assertJsonPath('code', ApiCode::UNAUTHORIZED->value);
        Http::assertNothingSent();
    }

    public function test_returns_upstream_business_error(): void
    {
        Http::fake([self::UPSTREAM_URL => Http::response([
            'code' => '0004',
            'message' => '请求报文格式或字段错误',
            'data' => null,
        ], 400)]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/agent/get-earnings', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
        ]);

        $response->assertStatus(400)->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '请求报文格式或字段错误',
            'data' => null,
        ]);
    }
}

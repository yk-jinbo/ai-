<?php

namespace Tests\Feature\Asset;

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
 * 组织净入金记录接口契约测试
 * 验证会员身份、可选日期和第三方请求参数
 */
class AssetNetInflowRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'asset-net-inflow-access-token';

    private const UPSTREAM_URL = 'https://assets.example.test/api/v1/computing/query/organizations/net-deposits';

    /** @var array<string, string> */
    private array $redisValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'asset-net-inflow-test-key');
        config()->set('services.asset_net_inflow_records.url', self::UPSTREAM_URL);

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

    /**
     * 验证前端省略全部时间字段时不会向第三方补充时间
     *
     * @return void
     */
    public function test_omits_all_unprovided_dates_from_upstream_request(): void
    {
        $data = ['items' => [], 'total' => 0, 'pageNo' => 1, 'pageSize' => 20];
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => $data,
            ]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/asset/net-inflow-records', [
            'assessmentType' => 'OPERATION_CENTER',
            'orgId' => '30001',
            'periodType' => 'QUARTER',
            'fromDate' => null,
            'toDate' => null,
        ]);

        $response->assertOk()->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '资产净流入记录查询成功',
            'data' => $data,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13900001111',
            'memberType' => 'NORMAL',
            'assessmentType' => 'OPERATION_CENTER',
            'orgId' => '30001',
            'periodType' => 'QUARTER',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);
    }
}

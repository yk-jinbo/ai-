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
 * 资产流水接口契约测试
 * 验证 Token 身份补充、前端筛选参数、分页默认值和第三方响应组装
 */
class AssetRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'asset-records-access-token';

    private const UPSTREAM_URL = 'https://assets.example.test/api/assets/records';

    /** @var array<string, string> */
    private array $redisValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'asset-records-test-key');
        config()->set('services.asset_records.url', self::UPSTREAM_URL);

        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('get')->andReturnUsing(fn (string $key): ?string => $this->redisValues[$key] ?? null);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($redis);
        $this->app->instance(Factory::class, $factory);

        $scope = $this->app->make(CacheManager::class)->scope('auth-tokens');
        $this->redisValues[$scope->key(self::TOKEN)] = json_encode([
            'user' => ['mobile' => '13800000002', 'member_type' => 'NORMAL'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Http::preventStrayRequests();
    }

    /**
     * 验证接口组合 Token 用户信息和前端参数，并返回标准化流水数据
     */
    public function test_returns_asset_records_from_upstream(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response($this->successfulUpstreamResponse()),
        ]);
        $filters = [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'businessType' => 'MEMBER_INCENTIVE',
            'assetType' => 'GCP',
            'assessmentPeriodType' => 'WEEK',
        ];

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', $filters);

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '资产流水查询成功',
            'data' => $this->successfulUpstreamResponse()['data'],
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === $this->expectedUpstreamParameters($filters));
    }

    /**
     * 验证上游流水的发生时间为空时仍返回整页记录，并保留发生日期和空值
     */
    public function test_returns_records_with_null_occurred_at(): void
    {
        $upstream = $this->successfulUpstreamResponse();
        $upstream['data']['items'][1]['occurredAt'] = null;
        $upstream['data']['pageSize'] = 10;
        Http::fake([self::UPSTREAM_URL => Http::response($upstream)]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', [
            'pageNo' => 1,
            'pageSize' => 10,
            'businessType' => '',
            'fromDate' => '',
            'toDate' => '',
            'assessmentPeriodType' => '',
            'assetType' => 'GCP',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '资产流水查询成功',
            'data' => $upstream['data'],
        ]);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13800000002',
            'memberType' => 'NORMAL',
            'pageNo' => 1,
            'pageSize' => 10,
            'assetType' => 'GCP',
        ]);
    }

    /**
     * 验证可空的发生时间仍拒绝数组等异常类型，避免输出错误结构
     */
    public function test_rejects_non_scalar_occurred_at(): void
    {
        $upstream = $this->successfulUpstreamResponse();
        $upstream['data']['items'][1]['occurredAt'] = ['invalid'];
        Http::fake([self::UPSTREAM_URL => Http::response($upstream)]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records');

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '本地校验资产服务响应失败：occurredAt 必须是标量或 null',
            'data' => null,
        ]);
    }

    /**
     * 验证截止日期早于起始日期时返回统一 422 响应
     */
    public function test_rejects_reversed_date_range(): void
    {
        Http::fake();
        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', [
            'fromDate' => '2026-10-27',
            'toDate' => '2026-10-01',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'code' => ApiCode::INVALID_PARAMS->value,
            'message' => '发生日期截止日不能早于起始日',
        ]);
        Http::assertNothingSent();
    }

    /**
     * 验证非法筛选类型和超限分页参数被 FormRequest 拒绝
     */
    public function test_rejects_invalid_filter_values(): void
    {
        Http::fake();
        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'pageSize' => 101,
            'businessType' => 'UNKNOWN',
            'assetType' => 'UNKNOWN',
            'assessmentPeriodType' => 'DAY',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'code',
            'message',
            'data' => ['pageSize', 'businessType', 'assetType', 'assessmentPeriodType'],
        ]);
        Http::assertNothingSent();
    }

    /**
     * 验证日期范围省略时不向第三方发送日期字段
     */
    public function test_allows_request_without_date_range(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response($this->successfulUpstreamResponse()),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', [
            'fromDate' => null,
            'toDate' => null,
        ]);

        $response->assertStatus(200);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'token' => self::TOKEN,
            'mobile' => '13800000002',
            'memberType' => 'NORMAL',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);
    }

    /**
     * 验证未携带 Token 时返回统一 401 响应
     */
    public function test_rejects_request_without_token(): void
    {
        Http::fake();

        $response = $this->postJson('/api/assets/records');

        $response->assertUnauthorized();
        $response->assertJsonPath('code', ApiCode::UNAUTHORIZED->value);
        Http::assertNothingSent();
    }

    /**
     * 验证第三方服务异常转换为本项目统一业务错误
     */
    public function test_returns_business_error_when_upstream_fails(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0002',
                'message' => '资产服务异常',
                'data' => null,
            ]),
        ]);
        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
        ]);

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '资产服务异常',
            'data' => null,
        ]);
    }

    /**
     * 验证上游业务错误消息会原样透传，不与本地响应格式校验混淆
     */
    public function test_preserves_upstream_format_error_message(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0002',
                'message' => '资产服务响应格式异常',
                'data' => null,
            ]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records');

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '资产服务响应格式异常',
            'data' => null,
        ]);
    }

    /**
     * 验证上游成功但缺少流水列表时返回明确的本地校验错误
     */
    public function test_reports_local_response_format_error_for_missing_record_lists(): void
    {
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => [
                    'total' => 0,
                    'pageNo' => 1,
                    'pageSize' => 20,
                    'items' => [],
                ],
            ]),
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/assets/records');

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '本地校验资产服务响应失败：流水响应必须包含 balances 和 items 数组',
            'data' => null,
        ]);
    }

    /**
     * 返回预期发送给第三方的查询参数
     *
     * @param  array<string, mixed>  $filters  前端筛选参数
     * @return array<string, int|string> 第三方查询参数
     */
    private function expectedUpstreamParameters(array $filters): array
    {
        return [
            'token' => self::TOKEN,
            'mobile' => '13800000002',
            'memberType' => 'NORMAL',
            'fromDate' => $filters['fromDate'],
            'toDate' => $filters['toDate'],
            'pageNo' => 1,
            'pageSize' => 20,
            'businessType' => $filters['businessType'],
            'assetType' => $filters['assetType'],
            'assessmentPeriodType' => $filters['assessmentPeriodType'],
        ];
    }

    /**
     * 返回第三方资产流水成功响应样例
     *
     * @return array<string, mixed> 第三方资产流水响应
     */
    private function successfulUpstreamResponse(): array
    {
        return [
            'code' => '0000',
            'message' => '成功',
            'data' => [
                'balances' => [
                    ['assetType' => 'GCP', 'balance' => '1212.55000000'],
                    ['assetType' => 'EXCHANGE_VOUCHER', 'balance' => '1212.55000000'],
                    ['assetType' => 'PICKUP_VOUCHER', 'balance' => '1212.55000000'],
                    ['assetType' => 'VALUE_COUPON', 'balance' => '1212.55000000'],
                    ['assetType' => 'TONGBAO', 'balance' => '1212.55000000'],
                ],
                'items' => [
                    [
                        'flowId' => '90003',
                        'businessType' => 'MEMBER_INCENTIVE',
                        'assetType' => 'CONTRIBUTION',
                        'direction' => 'DECREASE',
                        'changeQuantity' => '-58.00000000',
                        'balanceAfter' => '42.00000000',
                        'occurredAt' => '2026-10-26T10:00:00+08:00',
                        'occurredDate' => '2026-10-26',
                        'assessmentPeriodType' => 'WEEK',
                        'periodStart' => '2026-10-18',
                        'periodEnd' => '2026-10-24',
                        'description' => '会员周激励结算清算贡献值',
                    ],
                    [
                        'flowId' => '90002',
                        'businessType' => 'MEMBER_INCENTIVE',
                        'assetType' => 'CONTRIBUTION',
                        'direction' => 'INCREASE',
                        'changeQuantity' => '58.00000000',
                        'balanceAfter' => '100.00000000',
                        'occurredAt' => '2026-10-26T10:00:00+08:00',
                        'occurredDate' => '2026-10-26',
                        'assessmentPeriodType' => 'WEEK',
                        'periodStart' => '2026-10-18',
                        'periodEnd' => '2026-10-24',
                        'description' => '会员周激励发放贡献值',
                    ],
                    [
                        'flowId' => '90001',
                        'businessType' => 'MEMBER_INCENTIVE',
                        'assetType' => 'GCP',
                        'direction' => 'INCREASE',
                        'changeQuantity' => '150.00000000',
                        'balanceAfter' => '1212.55000000',
                        'occurredAt' => '2026-10-26T10:00:00+08:00',
                        'occurredDate' => '2026-10-26',
                        'assessmentPeriodType' => 'WEEK',
                        'periodStart' => '2026-10-18',
                        'periodEnd' => '2026-10-24',
                        'description' => '会员周激励发放GCP',
                    ],
                ],
                'total' => 3,
                'pageNo' => 1,
                'pageSize' => 20,
            ],
        ];
    }
}

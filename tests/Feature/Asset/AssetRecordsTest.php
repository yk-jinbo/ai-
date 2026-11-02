<?php

namespace Tests\Feature\Asset;

use App\Enums\ApiCode;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 资产流水接口契约测试
 * 验证 Token 身份补充、前端筛选参数、分页默认值和第三方响应组装
 */
class AssetRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM_URL = 'https://assets.example.test/api/assets/records';

    /**
     * 验证接口组合 Token 用户信息和前端参数，并返回标准化流水数据
     *
     * @return void
     */
    public function test_returns_asset_records_from_upstream(): void
    {
        config()->set('services.asset_records.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response($this->successfulUpstreamResponse()),
        ]);
        $this->mockAuthenticatedMember();
        $filters = [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'businessType' => 'MEMBER_INCENTIVE',
            'assetType' => 'GCP',
            'assessmentPeriodType' => 'WEEK',
        ];

        $response = $this->withToken('frontend-token')->postJson('/api/assets/records', $filters);

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
     * 验证截止日期早于起始日期时返回统一 422 响应
     *
     * @return void
     */
    public function test_rejects_reversed_date_range(): void
    {
        Http::fake();
        $this->mockAuthenticatedMember();

        $response = $this->withToken('frontend-token')->postJson('/api/assets/records', [
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
     *
     * @return void
     */
    public function test_rejects_invalid_filter_values(): void
    {
        Http::fake();
        $this->mockAuthenticatedMember();

        $response = $this->withToken('frontend-token')->postJson('/api/assets/records', [
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
     * 验证未携带 Token 时返回统一 401 响应
     *
     * @return void
     */
    public function test_rejects_request_without_token(): void
    {
        Http::fake();

        $response = $this->postJson('/api/assets/records', [
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'code' => ApiCode::UNAUTHORIZED->value,
            'message' => '未认证或令牌无效',
        ]);
        Http::assertNothingSent();
    }

    /**
     * 验证第三方服务异常转换为本项目统一业务错误
     *
     * @return void
     */
    public function test_returns_business_error_when_upstream_fails(): void
    {
        config()->set('services.asset_records.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0002',
                'message' => '资产服务异常',
                'data' => null,
            ]),
        ]);
        $this->mockAuthenticatedMember();

        $response = $this->withToken('frontend-token')->postJson('/api/assets/records', [
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
     * 模拟 Token 对应的登录会员信息
     *
     * @return void
     */
    private function mockAuthenticatedMember(): void
    {
        $this->mock(AuthTokenService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('currentUser')
                ->once()
                ->andReturn([
                    'tenantId' => 'demo',
                    'mobile' => '13800000001',
                    'member_type' => 'NORMAL',
                ]);
        });
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
            'tenantId' => 'demo',
            'mobile' => '13800000001',
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

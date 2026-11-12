<?php

namespace Tests\Feature\Asset;

use App\Enums\ApiCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 资产汇总接口契约测试
 * 验证第三方请求报文、响应组装和关键失败状态
 */
class AssetSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM_URL = 'https://assets.example.test/member/summary';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 验证接口转发已校验参数，并返回标准化资产列表
     *
     * @return void
     */
    public function test_returns_normalized_asset_summary_from_upstream(): void
    {
        config()->set('services.asset_summary.url', self::UPSTREAM_URL);
        $upstreamResponse = $this->successfulUpstreamResponse();
        Http::fake([
            self::UPSTREAM_URL => Http::response($upstreamResponse),
        ]);
        Carbon::setTestNow('2026-11-02 15:00:00');
        $parameters = $this->expectedUpstreamParameters();
        $visibleAssets = array_slice($upstreamResponse['data']['assets'], 0, -1);

        $response = $this->postJson('/api/asset/summary', $parameters);

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '资产汇总查询成功',
            'data' => ['assets' => $visibleAssets],
        ]);
        $response->assertJsonMissing(['assetType' => 'VALUE_COUPON']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === $parameters);
    }

    /**
     * 验证汇总接口仅支持 POST
     *
     * @return void
     */
    public function test_rejects_get_route(): void
    {
        Http::fake();

        $response = $this->getJson('/api/asset/summary');

        $response->assertStatus(405);
        Http::assertNothingSent();
    }

    /**
     * 验证汇总接口不在本地校验请求参数，直接转发给第三方
     *
     * @return void
     */
    public function test_forwards_summary_parameters_without_local_validation(): void
    {
        config()->set('services.asset_summary.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0004',
                'message' => '租户 ID 不能为空',
                'data' => null,
            ]),
        ]);

        $response = $this->postJson('/api/asset/summary');

        $response->assertStatus(422);
        $response->assertJson([
            'code' => ApiCode::INVALID_PARAMS->value,
            'message' => '租户 ID 不能为空',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->data() === $this->expectedUpstreamParameters());
    }

    /**
     * 验证第三方业务失败时返回统一业务错误而不是伪成功数据
     *
     * @return void
     */
    public function test_returns_business_error_when_upstream_rejects_request(): void
    {
        config()->set('services.asset_summary.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0002',
                'message' => '资产服务异常',
                'data' => null,
            ]),
        ]);
        $response = $this->postJson('/api/asset/summary', $this->expectedUpstreamParameters());

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '资产服务异常',
            'data' => null,
        ]);
    }

    /**
     * 验证第三方参数错误码转换为本项目统一的 422 响应
     *
     * @return void
     */
    public function test_maps_upstream_parameter_error_to_invalid_params(): void
    {
        config()->set('services.asset_summary.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0004',
                'message' => '查询参数错误',
                'data' => null,
            ]),
        ]);
        $response = $this->postJson('/api/asset/summary', $this->expectedUpstreamParameters());

        $response->assertStatus(422);
        $response->assertExactJson([
            'code' => ApiCode::INVALID_PARAMS->value,
            'message' => '查询参数错误',
            'data' => null,
        ]);
    }

    /**
     * 返回预期发送给第三方的资产查询参数
     *
     * @return array{tenantId: string, mobile: string, memberType: string, asOfDate: string} 第三方查询参数
     */
    private function expectedUpstreamParameters(): array
    {
        return [
            'tenantId' => 'demo',
            'mobile' => '13800000002',
            'memberType' => 'NORMAL',
            'asOfDate' => '2026-11-02',
        ];
    }

    /**
     * 返回第三方成功响应样例
     *
     * @return array<string, mixed> 第三方资产响应
     */
    private function successfulUpstreamResponse(): array
    {
        return [
            'code' => '0000',
            'message' => '成功',
            'data' => [
                'assets' => [
                    [
                        'assetType' => 'GCP',
                        'obtainedQuantity' => '100000.00000000',
                        'visible' => true,
                    ],
                    [
                        'assetType' => 'EXCHANGE_VOUCHER',
                        'obtainedQuantity' => '100000.00000000',
                        'visible' => true,
                    ],
                    [
                        'assetType' => 'PICKUP_VOUCHER',
                        'obtainedQuantity' => null,
                        'visible' => true,
                    ],
                    [
                        'assetType' => 'TONGBAO',
                        'obtainedQuantity' => '100000.00000000',
                        'visible' => null,
                    ],
                    [
                        'assetType' => 'VALUE_COUPON',
                        'obtainedQuantity' => '100000.00000000',
                        'visible' => false,
                    ],
                ],
            ],
        ];
    }
}

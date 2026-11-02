<?php

namespace Tests\Feature\Assessment;

use App\Enums\ApiCode;
use App\Enums\AssetBusinessType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 考核业务类型接口契约测试
 * 验证第三方请求格式、统一响应和上游错误处理
 */
class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM_URL = 'https://assets.example.test/api/v1/computing/query/business-types';

    private const PERSONAL_SUMMARY_URL = 'https://assets.example.test/api/v1/computing/query/trades/personal/summary';

    private const DIRECT_SUMMARY_URL = 'https://assets.example.test/api/v1/computing/query/trades/direct/summary';

    /**
     * 验证接口使用空 POST 请求并返回第三方业务类型列表
     *
     * @return void
     */
    public function test_returns_business_types_from_upstream(): void
    {
        config()->set('services.business_types.url', self::UPSTREAM_URL);
        $items = [
            ['businessType' => 'MEMBER_TRADE', 'businessTypeName' => '个人易货'],
            ['businessType' => 'MEMBER_ASSESS', 'businessTypeName' => '会员考核'],
        ];
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['items' => $items],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/get-list');

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '获取考核业务类型列表成功',
            'data' => $items,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPSTREAM_URL
            && $request->data() === []);
    }

    /**
     * 验证第三方业务失败时接口返回统一业务错误
     *
     * @return void
     */
    public function test_returns_error_when_upstream_fails(): void
    {
        config()->set('services.business_types.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0002',
                'message' => '考核类型查询失败',
                'data' => null,
            ]),
        ]);

        $response = $this->postJson('/api/assessment/get-list');

        $response->assertStatus(400);
        $response->assertExactJson([
            'code' => ApiCode::ERROR->value,
            'message' => '考核类型查询失败',
            'data' => null,
        ]);
    }

    /**
     * 验证第三方列表项结构异常时不会返回伪成功数据
     *
     * @return void
     */
    public function test_rejects_invalid_upstream_item_shape(): void
    {
        config()->set('services.business_types.url', self::UPSTREAM_URL);
        Http::fake([
            self::UPSTREAM_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['items' => [['businessType' => 'MEMBER_TRADE']]],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/get-list');

        $response->assertStatus(400);
        $response->assertJson([
            'code' => ApiCode::ERROR->value,
            'message' => '考核业务类型响应格式异常',
            'data' => null,
        ]);
    }

    /**
     * 验证个人易货类型路由到个人交易汇总接口并携带联调身份参数
     *
     * @return void
     */
    public function test_assessment_info_routes_member_trade_to_personal_summary(): void
    {
        config()->set('services.assessment_info.urls.MEMBER_TRADE', self::PERSONAL_SUMMARY_URL);
        $data = ['assets' => [], 'tradeAmount' => '0', 'tradeDate' => null, 'visible' => true];
        Http::fake([
            self::PERSONAL_SUMMARY_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => $data,
            ]),
        ]);

        $response = $this->postJson('/api/assessment/assessment-info', [
            'businessType' => 'MEMBER_TRADE',
            'orgId' => 30002,
            'asOfDate' => '2026-11-02',
        ]);

        $response->assertOk()->assertJsonPath('data', $data);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::PERSONAL_SUMMARY_URL
            && $request->data() === [
                'asOfDate' => '2026-11-02',
                'orgId' => 30002,
                'tenantId' => 'demo',
                'mobile' => '13800000002',
                'memberType' => 'NORMAL',
            ]);
    }

    /**
     * 验证直推易货类型路由到直推交易汇总接口
     *
     * @return void
     */
    public function test_assessment_info_routes_direct_trade_to_direct_summary(): void
    {
        config()->set('services.assessment_info.urls.DIRECT_TRADE', self::DIRECT_SUMMARY_URL);
        Http::fake([
            self::DIRECT_SUMMARY_URL => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['assets' => []],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/assessment-info', ['businessType' => 'DIRECT_TRADE']);

        $response->assertOk()->assertJsonPath('data.assets', []);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DIRECT_SUMMARY_URL);
    }

    /**
     * 验证分公司考核请求会把机构 ID 传递给对应第三方接口
     *
     * @return void
     */
    public function test_assessment_info_passes_branch_company_org_id(): void
    {
        $url = 'https://assets.example.test/api/v1/computing/query/assessments/branch-company';
        config()->set('services.assessment_info.urls.BRANCH_COMPANY', $url);
        Http::fake([
            $url => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['orgId' => 30002],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/assessment-info', [
            'businessType' => 'BRANCH_COMPANY',
            'orgId' => 30002,
        ]);

        $response->assertOk()->assertJsonPath('data.orgId', 30002);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->data() === [
                'orgId' => 30002,
                'tenantId' => 'demo',
                'mobile' => '13800000002',
                'memberType' => 'NORMAL',
            ]);
    }

    /**
     * 验证招商公司考核请求会把招商公司 ID 传递给对应第三方接口
     *
     * @return void
     */
    public function test_assessment_info_passes_merchant_company_id(): void
    {
        $url = 'https://assets.example.test/api/v1/computing/query/assessments/merchant';
        config()->set('services.assessment_info.urls.MERCHANT_COMPANY', $url);
        Http::fake([
            $url => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['merchantId' => 40001],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/assessment-info', [
            'businessType' => 'MERCHANT_COMPANY',
            'merchantId' => 40001,
        ]);

        $response->assertOk()->assertJsonPath('data.merchantId', 40001);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->data() === [
                'merchantId' => 40001,
                'tenantId' => 'demo',
                'mobile' => '13800000002',
                'memberType' => 'NORMAL',
            ]);
    }

    /**
     * 验证代理查询会透传日期区间和分页参数
     *
     * @return void
     */
    public function test_assessment_info_passes_agent_filters(): void
    {
        $url = 'https://assets.example.test/api/v1/computing/query/agents/exchanges';
        config()->set('services.assessment_info.urls.AGENT', $url);
        Http::fake([
            $url => Http::response([
                'code' => '0000',
                'message' => '成功',
                'data' => ['items' => []],
            ]),
        ]);

        $response = $this->postJson('/api/assessment/assessment-info', [
            'businessType' => 'AGENT',
            'fromDate' => '2026-10-01',
            'toDate' => '2026-10-27',
            'pageNo' => 1,
            'pageSize' => 20,
        ]);

        $response->assertOk()->assertJsonPath('data.items', []);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->data() === [
                'fromDate' => '2026-10-01',
                'toDate' => '2026-10-27',
                'pageNo' => 1,
                'pageSize' => 20,
                'tenantId' => 'demo',
                'mobile' => '13800000002',
                'memberType' => 'NORMAL',
            ]);
    }

    /**
     * 验证未知业务类型不会调用第三方接口
     *
     * @return void
     */
    public function test_assessment_info_rejects_invalid_business_type(): void
    {
        Http::fake();

        $response = $this->postJson('/api/assessment/assessment-info', ['businessType' => 'UNKNOWN']);

        $response->assertStatus(422)->assertJsonPath('code', ApiCode::INVALID_PARAMS->value);
        Http::assertNothingSent();
    }

    /**
     * 验证业务类型枚举的 11 个值都已配置第三方地址
     *
     * @return void
     */
    public function test_assessment_info_has_upstream_url_for_every_business_type(): void
    {
        $configuredUrls = config('services.assessment_info.urls');
        $businessTypes = array_map(
            static fn (AssetBusinessType $type): string => $type->value,
            AssetBusinessType::cases(),
        );

        $this->assertIsArray($configuredUrls);
        $this->assertSame($businessTypes, array_keys($configuredUrls));
        foreach ($businessTypes as $businessType) {
            $this->assertIsString($configuredUrls[$businessType]);
            $this->assertNotSame('', trim($configuredUrls[$businessType]));
        }
    }
}

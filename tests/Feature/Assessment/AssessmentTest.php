<?php

namespace Tests\Feature\Assessment;

use App\Enums\ApiCode;
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
}

<?php

namespace Tests\Feature\Test;

use App\Enums\ApiCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 测试接口的 HTTP 契约测试。
 * 验证匿名访问、消息校验和错误响应，并将数据库隔离在 SQLite 内存库中。
 */
class TestEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证匿名请求能够获得用于辨认新部署的固定版本标记。
     */
    public function test_returns_deployment_marker_without_authentication(): void
    {
        $response = $this->getJson('/api/test');

        $response->assertStatus(200);
        $response->assertJsonStructure(['code', 'message', 'data' => ['status', 'version', 'message']]);
        $response->assertExactJson([
            'code' => ApiCode::SUCCESS->value,
            'message' => '测试接口访问成功',
            'data' => [
                'status' => 'ok',
                'version' => 'test-api-v1',
                'message' => '自动部署测试成功',
            ],
        ]);
    }

    /**
     * 验证校验后的测试消息能进入服务并按 JSON 格式返回。
     */
    public function test_returns_a_custom_message(): void
    {
        $response = $this->getJson('/api/test?'.http_build_query(['message' => '部署验证']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['code', 'message', 'data']);
        $response->assertJson(['code' => ApiCode::SUCCESS->value, 'data' => ['message' => '部署验证']]);
    }

    /**
     * 验证数组消息被 FormRequest 拒绝并返回统一的 422 响应。
     */
    public function test_rejects_a_non_string_message(): void
    {
        $response = $this->getJson('/api/test?message[]=invalid');

        $response->assertStatus(422);
        $response->assertJsonStructure(['code', 'message', 'data' => ['message']]);
        $response->assertJson(['code' => ApiCode::INVALID_PARAMS->value, 'message' => '测试消息必须是字符串']);
    }

    /**
     * 验证超长消息被拒绝，避免公开测试接口接收无界输入。
     */
    public function test_rejects_an_overlong_message(): void
    {
        $response = $this->getJson('/api/test?'.http_build_query(['message' => str_repeat('a', 101)]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['code', 'message', 'data' => ['message']]);
        $response->assertJson(['code' => ApiCode::INVALID_PARAMS->value, 'message' => '测试消息不能超过100个字符']);
    }

    /**
     * 验证未定义的子路径由全局异常处理返回统一 404 响应。
     */
    public function test_returns_not_found_for_an_undefined_path(): void
    {
        $response = $this->getJson('/api/test/missing');

        $response->assertStatus(404);
        $response->assertJsonStructure(['code', 'message', 'data']);
        $response->assertJson(['code' => ApiCode::NOT_FOUND->value, 'message' => '资源不存在', 'data' => null]);
    }
}

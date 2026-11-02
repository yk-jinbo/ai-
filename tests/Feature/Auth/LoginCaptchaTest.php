<?php

namespace Tests\Feature\Auth;

use App\Services\LoginCaptchaService;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 登录验证码接口回归测试。
 * 使用真实图片资源和完整控制器依赖，只替代 Redis I/O，验证部署依赖和前端响应契约。
 */
class LoginCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private Connection $redis;

    /**
     * 注入隔离的 Redis 连接并设置测试缓存密钥，禁止访问实际外部服务。
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'captcha-test-only-key');
        $this->redis = Mockery::mock(Connection::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($this->redis);
        $this->app->instance(Factory::class, $factory);
        Http::preventStrayRequests();
    }

    /** 验证与用户 curl 相同的匿名 GET 请求可生成图形，并将坐标只保存在 Redis。 */
    public function test_captcha_returns_images_and_stores_the_answer_without_external_calls(): void
    {
        $storedKey = null;
        $storedPayload = null;
        $this->redis->shouldReceive('setex')->once()->withArgs(function (string $key, int $ttl, string $payload) use (&$storedKey, &$storedPayload): bool {
            $storedKey = $key;
            $storedPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            return $ttl === 120 && is_int($storedPayload['target_x'] ?? null);
        })->andReturn(true);

        $response = $this->get('/api/auth/captcha', ['Accept' => '*/*', 'User-Agent' => 'Apifox/1.0.0']);
        $response->assertStatus(200);
        $response->assertJsonStructure(['code', 'message', 'data' => ['captcha_id', 'background_image', 'slider_image', 'background_width', 'background_height', 'piece_width', 'piece_height', 'piece_initial_x', 'piece_y', 'expires_in']]);
        $response->assertJson(['code' => 0, 'data' => ['background_width' => 320, 'background_height' => 160, 'piece_width' => 40, 'piece_height' => 40, 'expires_in' => 120]]);
        $response->assertJsonMissingPath('data.target_x');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('data.captcha_id'));
        $this->assertStringContainsString(':login-captchas:', $storedKey);
        foreach (['background_image', 'slider_image'] as $field) {
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.'.$field));
            $svg = base64_decode(explode(',', $response->json('data.'.$field), 2)[1], true);
            $this->assertNotFalse(simplexml_load_string($svg));
            $this->assertStringContainsString('data:image/png;base64,', $svg);
        }

        $this->redis->shouldReceive('eval')->once()->withArgs(fn (string $script, int $keyCount, string $key): bool => $keyCount === 1 && $key === $storedKey)->andReturn(json_encode($storedPayload));
        $this->app->make(LoginCaptchaService::class)->verify($response->json('data.captcha_id'), $storedPayload['target_x']);
        Http::assertNothingSent();
    }

    /** 验证缓存写入失败不会签发无法校验的验证码。 */
    public function test_captcha_does_not_return_a_challenge_if_redis_write_fails(): void
    {
        $this->redis->shouldReceive('setex')->once()->andReturn(false);
        $response = $this->getJson('/api/auth/captcha');

        $response->assertStatus(400);
        $response->assertExactJson(['code' => -1, 'message' => '滑块验证码创建失败', 'data' => null]);
        Http::assertNothingSent();
    }
}

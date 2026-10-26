<?php

namespace App\Services;

use App\Caches\CacheManager;
use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use JsonException;

/**
 * 创建并一次性校验登录滑块验证码。
 */
class LoginCaptchaService
{
    private const CACHE_SCOPE = 'login-captchas';

    private const TTL_SECONDS = 120;

    private const OFFSET_TOLERANCE_PIXELS = 15;

    private const CONSUME_LUA = <<<'LUA'
local payload = redis.call('GET', KEYS[1])
if not payload then
    return false
end
redis.call('DEL', KEYS[1])
return payload
LUA;

    /**
     * @param  CacheManager  $cacheManager  Redis 缓存适配器
     * @param  CaptchaImageGenerator  $captchaImageGenerator  滑块图片生成器
     */
    public function __construct(
        private CacheManager $cacheManager,
        private CaptchaImageGenerator $captchaImageGenerator,
    ) {}

    /**
     * 创建验证码并将目标横坐标保存到 Redis。
     *
     * 业务逻辑：仅保存目标坐标，避免将可直接通过验证的坐标返回给前端。
     *
     * @return LoginCaptchaResult 前端渲染和提交所需的验证码数据
     *
     * @throws JsonException 目标坐标序列化失败时抛出
     */
    public function create(): LoginCaptchaResult
    {
        $image = $this->captchaImageGenerator->generate();
        $captchaId = bin2hex(random_bytes(32));
        $payload = json_encode(['target_x' => $image->targetX], JSON_THROW_ON_ERROR);
        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $stored = $scope->connection()->setex($scope->key($captchaId), self::TTL_SECONDS, $payload);

        if ($stored === false) {
            ResponseHelper::throwError('滑块验证码创建失败', ApiCode::ERROR);
        }

        return new LoginCaptchaResult(
            $captchaId,
            $image->backgroundImage,
            $image->sliderImage,
            CaptchaImageGenerator::BACKGROUND_WIDTH,
            CaptchaImageGenerator::BACKGROUND_HEIGHT,
            CaptchaImageGenerator::PIECE_WIDTH,
            CaptchaImageGenerator::PIECE_HEIGHT,
            CaptchaImageGenerator::PIECE_INITIAL_X,
            $image->targetY,
            self::TTL_SECONDS,
        );
    }

    /**
     * 校验并一次性消费滑块验证码。
     *
     * 业务逻辑：通过 Lua 原子读取并删除，避免同一验证码被重复猜测或重复使用。
     *
     * @param  string  $captchaId  前端回传的验证码标识
     * @param  int  $offsetX  基于背景原始宽度换算的拼图块 X 坐标
     */
    public function verify(string $captchaId, int $offsetX): void
    {
        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $payload = $scope->connection()->eval(
            self::CONSUME_LUA,
            1,
            $scope->key($captchaId),
        );

        ResponseHelper::errorIf(
            ! is_string($payload) || $payload === '',
            '滑块验证码校验失败',
            ApiCode::INVALID_PARAMS,
        );

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            ResponseHelper::throwError('滑块验证码校验失败', ApiCode::INVALID_PARAMS);

            return;
        }

        $targetX = is_array($data) ? ($data['target_x'] ?? null) : null;
        ResponseHelper::errorIf(
            ! is_int($targetX) || abs($offsetX - $targetX) > self::OFFSET_TOLERANCE_PIXELS,
            '滑块验证码校验失败',
            ApiCode::INVALID_PARAMS,
        );
    }
}

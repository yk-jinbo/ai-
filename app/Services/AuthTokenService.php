<?php

namespace App\Services;

use App\Caches\CacheManager;
use Illuminate\Auth\AuthenticationException;
use JsonException;

/**
 * 外部会员令牌的本地 Redis 适配器。
 *
 * 上游 accessToken 作为登录标识使用，本地只保存它对应的用户信息和过期时间，不生成数据库令牌。
 */
class AuthTokenService
{
    private const CACHE_SCOPE = 'auth-tokens';

    public function __construct(private CacheManager $cacheManager) {}

    /**
     * 保存上游令牌及其用户信息。
     *
     * @param  array<string, mixed>  $user  供后续业务接口使用的用户信息
     */
    public function store(string $token, array $user, ?int $expiresIn = null): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new AuthenticationException('登录令牌为空');
        }

        try {
            $payload = json_encode([
                'user' => $user,
                'issued_at' => now()->toISOString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \RuntimeException('登录信息缓存失败', previous: $exception);
        }

        $ttl = max(1, $expiresIn ?? (int) config('services.member_auth.token_ttl_seconds', 172800));
        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $stored = $scope->connection()->setex($scope->key($token), $ttl, $payload);

        if ($stored === false) {
            throw new \RuntimeException('登录信息缓存失败');
        }
    }

    /**
     * 根据令牌返回本地保存的用户信息。
     *
     * 其他业务接口只需传入请求中的 Bearer 令牌调用此方法，无需重复解析 Redis 数据。
     *
     * @return array<string, mixed>
     */
    public function currentUser(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $raw = $scope->connection()->get($scope->key($token));
        if (! is_string($raw) || $raw === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AuthenticationException('未认证或令牌无效');
        }

        if (! is_array($payload['user'] ?? null)) {
            throw new AuthenticationException('未认证或令牌无效');
        }

        return $payload['user'];
    }

    /** 清除本地保存的令牌及用户信息。 */
    public function revoke(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $scope->connection()->del($scope->key($token));
    }
}

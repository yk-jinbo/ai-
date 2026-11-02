<?php

namespace App\Services;

use App\Caches\CacheManager;
use App\Caches\CacheScope;
use Illuminate\Auth\AuthenticationException;
use JsonException;

/**
 * 外部会员令牌的本地 Redis 适配器。
 *
 * 上游 accessToken 作为登录标识使用，本地只保存它对应的用户信息和过期时间，不生成数据库令牌。
 * 身份确认前的手机号也按短期票据暂存于独立 Redis 作用域，确认成功后立即清理。
 */
class AuthTokenService
{
    private const CACHE_SCOPE = 'auth-tokens';

    private const LONG_TOKEN_CACHE_SCOPE = 'auth-token-digests';

    private const PENDING_LOGIN_CACHE_SCOPE = 'auth-login-pending';

    private const MAX_TOKEN_IDENTIFIER_BYTES = 256;

    private const TOKEN_HASH_ALGORITHM = 'sha256';

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
        $scope = $this->tokenScope($token);
        $stored = $scope->connection()->setex($scope->key($this->tokenIdentifier($token)), $ttl, $payload);

        if ($stored === false) {
            throw new \RuntimeException('登录信息缓存失败');
        }
    }

    /**
     * 暂存登录校验阶段的手机号，供身份确认成功后写入用户信息。
     *
     * @param  string  $ticket  上游登录校验票据
     * @param  string  $mobile  登录手机号
     * @param  int  $expiresIn  票据剩余有效秒数
     */
    public function storePendingMobile(string $ticket, string $mobile, int $expiresIn): void
    {
        $ticket = trim($ticket);
        $mobile = trim($mobile);
        if ($ticket === '' || $mobile === '') {
            throw new \InvalidArgumentException('登录票据和手机号不能为空');
        }

        $scope = $this->cacheManager->scope(self::PENDING_LOGIN_CACHE_SCOPE);
        $stored = $scope->connection()->setex(
            $scope->key($this->boundedIdentifier($ticket)),
            max(1, $expiresIn),
            $mobile,
        );

        if ($stored === false) {
            throw new \RuntimeException('登录手机号缓存失败');
        }
    }

    /**
     * 获取登录确认阶段暂存的手机号。
     *
     * @param  string  $ticket  上游登录校验票据
     * @return string|null 暂存手机号，不存在或已过期时返回 null
     */
    public function pendingMobile(string $ticket): ?string
    {
        $ticket = trim($ticket);
        if ($ticket === '') {
            return null;
        }

        $scope = $this->cacheManager->scope(self::PENDING_LOGIN_CACHE_SCOPE);
        $mobile = $scope->connection()->get($scope->key($this->boundedIdentifier($ticket)));

        return is_string($mobile) && $mobile !== '' ? $mobile : null;
    }

    /**
     * 清理已完成登录确认的手机号暂存。
     *
     * @param  string  $ticket  上游登录校验票据
     */
    public function forgetPendingMobile(string $ticket): void
    {
        $ticket = trim($ticket);
        if ($ticket === '') {
            return;
        }

        $scope = $this->cacheManager->scope(self::PENDING_LOGIN_CACHE_SCOPE);
        $scope->connection()->del($scope->key($this->boundedIdentifier($ticket)));
    }

    /**
     * 根据令牌返回本地保存的用户信息。
     *
     * 其他业务接口只需传入请求中的 Bearer 令牌调用此方法，无需重复解析 Redis 数据。
     *
     * @return array<string, mixed>
     */
    public function currentUser(): array
    {
        $token = trim((string) request()->bearerToken());
        if ($token === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        $scope = $this->tokenScope($token);
        $raw = $scope->connection()->get($scope->key($this->tokenIdentifier($token)));
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
    public function revoke(): void
    {
        $token = trim((string) request()->bearerToken());
        if ($token === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        $scope = $this->tokenScope($token);
        $scope->connection()->del($scope->key($this->tokenIdentifier($token)));
    }

    private function tokenScope(string $token): CacheScope
    {
        // 隔离长令牌会话，避免提交摘要字符串的请求按短令牌路径命中同一 key。
        if (strlen($token) > self::MAX_TOKEN_IDENTIFIER_BYTES) {
            return $this->cacheManager->scope(self::LONG_TOKEN_CACHE_SCOPE);
        }

        return $this->cacheManager->scope(self::CACHE_SCOPE);
    }

    private function tokenIdentifier(string $token): string
    {
        return $this->boundedIdentifier($token);
    }

    private function boundedIdentifier(string $identifier): string
    {
        if (strlen($identifier) <= self::MAX_TOKEN_IDENTIFIER_BYTES) {
            return $identifier;
        }

        // JWT 或票据可能超过缓存标识限制；只摘要长标识，避免把原文放入 Redis key。
        return self::TOKEN_HASH_ALGORITHM.':'.hash(self::TOKEN_HASH_ALGORITHM, $identifier);
    }
}

<?php

namespace App\Services;

use App\Caches\CacheManager;
use App\Enums\AuthLoginType;
use App\Models\MemberModel;
use Illuminate\Auth\AuthenticationException;
use JsonException;

class AuthTokenService
{
    private const CACHE_SCOPE = 'auth-tokens';

    private const TOKEN_TTL_SECONDS = 604800;

    public function __construct(private CacheManager $cacheManager) {}

    /** 生成并缓存访问令牌。 */
    public function issue(MemberModel $member, AuthLoginType $loginType, string $identifier): array
    {
        $token = 'auth_'.bin2hex(random_bytes(32));
        $user = $this->userPayload($member, $loginType, $identifier);
        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $payload = json_encode(['user' => $user, 'issued_at' => now()->toISOString()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($scope->connection()->setex($scope->key($token), self::TOKEN_TTL_SECONDS, $payload) === false) {
            throw new \RuntimeException('登录信息缓存失败');
        }

        return ['token' => $token, 'token_type' => 'Bearer', 'user' => $user];
    }

    /** 读取当前登录用户。 */
    public function currentUser(string $token): array
    {
        $scope = $this->cacheManager->scope(self::CACHE_SCOPE);
        $raw = $scope->connection()->get($scope->key(trim($token)));
        if (! is_string($raw) || $raw === '') {
            throw new AuthenticationException('未认证或令牌无效');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthenticationException('未认证或令牌无效');
        }

        if (! is_array($payload['user'] ?? null)) {
            throw new AuthenticationException('未认证或令牌无效');
        }

        return $payload['user'];
    }

    private function userPayload(MemberModel $member, AuthLoginType $loginType, string $identifier): array
    {
        $user = $member->toArray();
        $memberId = (int) $member->getKey();
        $user['id'] = $memberId;
        $user['member_id'] = $memberId;
        $user['identifier'] = $identifier;
        $user['login_type'] = $loginType->value;

        return $user;
    }
}

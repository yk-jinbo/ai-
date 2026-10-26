<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use App\Services\AuthTokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 限制同一客户端访问同一接口的频率。
 */
final class ApiRateLimitMiddleware
{
    private const MAX_ATTEMPTS = 2;

    private const DECAY_SECONDS = 1;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly AuthTokenService $authTokenService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);

        if ($this->rateLimiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return ResponseHelper::error(
                '请求频繁，请稍后再试',
                ApiCode::ERROR,
                null,
                Response::HTTP_TOO_MANY_REQUESTS,
            )->header('Retry-After', (string) max(1, $this->rateLimiter->availableIn($key)));
        }

        $this->rateLimiter->hit($key, self::DECAY_SECONDS);

        return $next($request);
    }

    private function key(Request $request): string
    {
        $route = $request->route();
        $endpoint = is_object($route)
            ? (string) ($route->getName() ?: $route->uri())
            : $request->path();

        return 'api-rate-limit:'.hash('sha256', implode('|', [
            $this->clientIdentifier($request),
            strtoupper($request->method()),
            $endpoint,
        ]));
    }

    private function clientIdentifier(Request $request): string
    {
        $token = trim((string) $request->bearerToken());

        if ($token !== '') {
            try {
                $this->authTokenService->currentUser($token);

                return 'token:'.$token;
            } catch (AuthenticationException) {
                // 无效 Token 仍按未登录请求使用 IP 限制。
            }
        }

        return 'ip:'.((string) ($request->ip() ?: 'unknown'));
    }
}

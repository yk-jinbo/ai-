<?php

namespace App\Http\Middleware;

use App\Services\AuthTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 从 Redis 校验本项目保存的上游会员令牌，并把用户信息放入请求属性。
 */
class AuthenticateMemberToken
{
    /**
     * 注入本地会员令牌服务。
     *
     * @param  AuthTokenService  $authTokenService  Redis 令牌存取服务
     * @return void
     */
    public function __construct(private AuthTokenService $authTokenService) {}

    /**
     * 校验 Bearer 令牌并把已缓存用户信息挂到请求属性。
     *
     * @param  Request  $request  当前 HTTP 请求
     * @param  Closure  $next  下一个中间件
     * @return Response 继续处理后的响应
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('member_auth_user', $this->authTokenService->currentUser());

        return $next($request);
    }
}

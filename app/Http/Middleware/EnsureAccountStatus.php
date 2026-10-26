<?php

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Enums\UserStatus;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountStatus
{
    /**
     * 确保账号状态可用
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 未认证的情况由 auth:sanctum 中间件处理
        if (! $user) {
            return ResponseHelper::error('未认证或令牌无效', ApiCode::UNAUTHORIZED, null, 401);
        }

        // 检查账号状态：只有 active 才能通过
        if ($user->status !== UserStatus::ACTIVE) {
            return ResponseHelper::error('用户名或密码错误', ApiCode::UNAUTHORIZED, null, 401);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use App\Models\AdminUserModel;
use App\Repositories\AdminUserRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(
        private AdminUserRepository $userRepository,
    ) {}

    /**
     * 检查权限
     *
     * @param  string|array  $permissions  权限编码或权限编码数组
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var AdminUserModel|null $user */
        $user = $request->user();

        // 未认证
        if (! $user) {
            return ResponseHelper::error('未认证或令牌无效', ApiCode::UNAUTHORIZED, null, 401);
        }

        // hasPermission 同时处理超级管理员绕过与普通账号权限查询。
        foreach ($permissions as $permission) {
            if (! $this->userRepository->hasPermission($user, $permission)) {
                return ResponseHelper::error('无权限访问', ApiCode::FORBIDDEN, null, 403);
            }
        }

        return $next($request);
    }
}

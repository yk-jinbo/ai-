<?php

namespace App\Http\Controllers\Api\Auth;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Services\AuthService;
use App\Services\LoginCaptchaService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('api/auth')]
class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private LoginCaptchaService $loginCaptchaService,
    ) {}

    /**
     * 获取登录滑块验证码。
     *
     * @param  AuthRequest  $request  已完成路由动作校验的请求
     * @return JsonResponse 滑块背景图、拼图块和布局参数
     */
    #[Get('captcha', name: 'api.auth.captcha')]
    public function captcha(AuthRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->loginCaptchaService->create()->toArray());
    }

    /**
     * 用户登录
     */
    #[Post('login', name: 'api.auth.login')]
    public function login(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->login(
            $data['username'],
            $data['password'],
            $data['captcha_id'],
            (int) $data['offset_x'],
        );

        return ResponseHelper::success($result, '登录成功333');
    }

    /**
     * 获取当前账号信息
     */
    #[Get('me', name: 'api.auth.me', middleware: ['auth:sanctum', 'account.status'])]
    public function me(AuthRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->authService->getCurrentUser($user);

        return ResponseHelper::success($data);
    }

    /**
     * 用户登出
     */
    #[Post('logout', name: 'api.auth.logout', middleware: ['auth:sanctum', 'account.status'])]
    public function logout(AuthRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authService->logout($user);

        return ResponseHelper::success(null, '登出成功');
    }
}

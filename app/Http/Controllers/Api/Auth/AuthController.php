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

/**
 * 会员认证中转控制器。
 *
 * 接收旧项目格式请求，调用认证服务并返回统一的 code/message/data 响应。
 */
#[Prefix('api/auth')]
class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private LoginCaptchaService $loginCaptchaService,
    ) {}

    #[Get('captcha', name: 'api.auth.captcha')]
    public function captcha(AuthRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->loginCaptchaService->create()->toArray());
    }

    #[Post('sms/send', name: 'api.auth.sms.send')]
    public function sendSms(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();

        $message = $this->authService->sendSms(
            $data['mobile'],
            $data['captcha_id'],
            (int) $data['offset_x'],
        );

        return ResponseHelper::success(null, $message);
    }

    #[Post('login/password', name: 'api.auth.login.password')]
    public function passwordLogin(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $username = (string) ($data['account'] ?? $data['username']);
        $result = $this->authService->preparePasswordLogin(
            $username,
            $data['password'],
            $data['captcha_id'],
            (int) $data['offset_x'],
        );

        return ResponseHelper::success($result->data, $result->message);
    }

    #[Post('login/sms', name: 'api.auth.login.sms')]
    public function smsLogin(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->prepareSmsLogin($data['mobile'], $data['code']);

        return ResponseHelper::success($result->data, $result->message);
    }

    #[Post('login/confirm', name: 'api.auth.login.confirm')]
    public function confirmLogin(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->confirmLogin(
            $data['ticket'],
            $data['member_type'],
            (bool) $data['agreement_accepted'],
        );

        return ResponseHelper::success($result->data, $result->message);
    }

    #[Post('logout', name: 'api.auth.logout', middleware: 'member.auth')]
    public function logout(AuthRequest $request): JsonResponse
    {
        $this->authService->logout();

        return ResponseHelper::success(null, '退出登录成功');
    }
}

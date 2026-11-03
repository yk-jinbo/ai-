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

    /**
     * 匿名获取所选身份的协议清单，禁止缓存以便客户端读取当前发布版本。
     *
     * @param  AuthRequest  $request  协议场景和身份参数
     * @return JsonResponse 统一格式的协议清单
     *
     * @throws \Exception 协议查询失败时由全局异常处理返回
     */
    #[Get('agreements/policy', name: 'api.auth.agreements.policy')]
    public function agreementPolicy(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->agreementPolicy($data['member_type'], $data['scene'] ?? 'LOGIN');

        return ResponseHelper::success($result->data, $result->message)->header('Cache-Control', 'no-store');
    }

    /**
     * 匿名读取清单中指定的协议版本正文，不记录或代替用户同意。
     *
     * @param  AuthRequest  $request  查询参数中的协议版本 ID，按字符串校验
     * @return JsonResponse 统一格式的协议正文
     *
     * @throws \Exception 正文查询失败时由全局异常处理返回
     */
    #[Get('agreements/versions', name: 'api.auth.agreements.version')]
    public function agreementVersion(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->agreementVersion($data['version_id']);

        return ResponseHelper::success($result->data, $result->message)->header('Cache-Control', 'no-store');
    }

    #[Post('login/confirm', name: 'api.auth.login.confirm')]
    public function confirmLogin(AuthRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->confirmLogin(
            $data['ticket'],
            $data['member_type'],
            $data['agreement_consent'] ?? null,
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

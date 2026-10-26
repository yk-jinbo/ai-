<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use App\Models\AdminUserModel;
use App\Repositories\AdminPersonalAccessTokenRepository;
use App\Repositories\AdminUserRepository;
use Illuminate\Support\Facades\DB;

class AuthService
{
    public function __construct(
        private AdminUserRepository $userRepository,
        private AdminPersonalAccessTokenRepository $personalAccessTokenRepository,
        private LoginCaptchaService $loginCaptchaService,
    ) {}

    /**
     * 校验滑块验证码并签发后台登录凭证。
     *
     * 业务逻辑：验证码在数据库事务前一次性消费，避免无效请求持有账号行锁。
     *
     * @param  string  $username  后台账号
     * @param  string  $password  后台密码
     * @param  string  $captchaId  滑块验证码标识
     * @param  int  $offsetX  滑块目标横坐标
     * @return array<string, mixed> 登录凭证和当前用户信息
     */
    public function login(string $username, string $password, string $captchaId, int $offsetX): array
    {
        $this->loginCaptchaService->verify($captchaId, $offsetX);

        DB::beginTransaction();
        try {
            // 锁定账号行，保证同一账号的旧凭证撤销与新凭证签发不会交叉执行。
            $user = $this->userRepository->findByUsernameForUpdate($username);

            // 验证用户是否存在、密码是否正确、账号状态是否可登录
            // 统一返回"用户名或密码错误"，不泄露账号是否存在或具体状态
            ResponseHelper::errorIf(
                ! $user || ! $this->userRepository->verifyPassword($user, $password) || ! $user->canLogin(),
                '用户名或密码错误',
                ApiCode::UNAUTHORIZED
            );

            // 并发安全：撤销旧凭证并签发新凭证
            $this->personalAccessTokenRepository->revokeAllFor($user);

            // 创建新令牌，有效期 7 天
            $token = $this->personalAccessTokenRepository->issueFor($user, now()->addDays(7));

            // 更新最后登录时间
            $this->userRepository->updateLastLoginAt($user);

            // 在事务内完成角色/权限快照，避免提交后序列化失败导致客户端拿不到已签发凭证。
            $serializedUser = $this->serializeUser($user);

            DB::commit();

            return [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => $serializedUser,
            ];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }
    }

    /**
     * 获取当前用户信息
     */
    public function getCurrentUser(AdminUserModel $user): array
    {
        return [
            'user' => $this->serializeUser($user),
        ];
    }

    /**
     * 用户登出
     */
    public function logout(AdminUserModel $user): void
    {
        $this->personalAccessTokenRepository->revokeCurrentFor($user);
    }

    /**
     * 按 AuthUser 契约生成脱敏账号数据。
     *
     * @return array<string, mixed>
     */
    private function serializeUser(AdminUserModel $user): array
    {
        $user = $this->userRepository->loadAuthorizationContext($user);
        $isSuperAdmin = $user->roles->contains(
            static fn ($role): bool => (bool) $role->is_super_admin,
        );
        $permissions = $user->roles
            ->flatMap(static fn ($role) => $role->permissions->pluck('code'))
            ->filter(static fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->all();

        if ($isSuperAdmin && ! in_array('*', $permissions, true)) {
            array_unshift($permissions, '*');
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'phone' => $user->phone,
            'status' => $user->status->value,
            'is_super_admin' => $isSuperAdmin,
            'roles' => $user->roles->pluck('code')->values()->all(),
            'permissions' => $permissions,
        ];
    }
}

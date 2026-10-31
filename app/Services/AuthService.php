<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use Illuminate\Support\Arr;

/**
 * 会员认证业务服务。
 *
 * 负责滑块校验、上游认证结果转换和外部令牌的本地 Redis 保存，不访问本地数据库。
 */
class AuthService
{
    private const DEFAULT_TICKET_TTL_SECONDS = 300;

    /**
     * 注入上游认证客户端、本地令牌服务和滑块验证码服务。
     *
     * @param  ExternalAuthClient  $externalAuthClient  上游会员认证客户端
     * @param  AuthTokenService  $authTokenService  本地 Redis 令牌服务
     * @param  LoginCaptchaService  $loginCaptchaService  本地滑块验证码服务
     * @return void
     */
    public function __construct(
        private ExternalAuthClient $externalAuthClient,
        private AuthTokenService $authTokenService,
        private LoginCaptchaService $loginCaptchaService,
    ) {}

    /** 发送短信前消费本地滑块验证码，再转发登录短信请求。 */
    public function sendSms(string $mobile, string $captchaId, int $offsetX): string
    {
        $this->loginCaptchaService->verify($captchaId, $offsetX);
        $result = $this->externalAuthClient->sendSms($mobile);
        $this->ensureSuccess($result, '验证码发送失败');

        return $result->message !== null && $result->message !== ''
            ? $result->message
            : '验证码已发送';
    }

    /** 校验账号密码并返回待确认的身份集合，不签发访问令牌。 */
    public function preparePasswordLogin(
        string $username,
        string $password,
        string $captchaId,
        int $offsetX,
    ): AuthLoginResult {
        $this->loginCaptchaService->verify($captchaId, $offsetX);
        $result = $this->externalAuthClient->preparePasswordLogin($username, $password);
        $this->ensureSuccess($result, '账号或密码错误');

        return new AuthLoginResult(
            $this->normalizePrepareData($result->data),
            $result->message !== null && $result->message !== '' ? $result->message : '登录校验成功',
        );
    }

    /** 校验短信验证码并返回待确认的身份集合，不签发访问令牌。 */
    public function prepareSmsLogin(string $mobile, string $code): AuthLoginResult
    {
        $result = $this->externalAuthClient->prepareSmsLogin($mobile, $code);
        $this->ensureSuccess($result, '验证码错误或已过期');

        return new AuthLoginResult(
            $this->normalizePrepareData($result->data),
            $result->message !== null && $result->message !== '' ? $result->message : '登录校验成功',
        );
    }

    /** 选择身份并确认协议，保存上游令牌后返回旧项目登录字段。 */
    public function confirmLogin(string $ticket, string $memberType, bool $agreementAccepted): AuthLoginResult
    {
        $result = $this->externalAuthClient->confirmLogin($ticket, $memberType, $agreementAccepted);
        $this->ensureSuccess($result, '登录确认失败');

        $data = $this->normalizeTokenData($result->data, $memberType);
        $token = (string) ($data['token'] ?? '');
        ResponseHelper::errorIf($token === '', '登录响应缺少访问令牌', ApiCode::ERROR);
        $user = $this->storedUser($result->data, $data, $memberType);
        $data['user'] = $user;

        $this->authTokenService->store(
            $token,
            $user,
            $data['expires_in'],
        );

        return new AuthLoginResult(
            $data,
            $result->message !== null && $result->message !== '' ? $result->message : '登录成功',
        );
    }

    /**
     * 返回当前令牌缓存的用户信息。
     *
     * @param  string  $token  上游访问令牌
     * @return array{user: array<string, mixed>} 当前用户信息
     */
    public function getCurrentUser(string $token): array
    {
        return ['user' => $this->authTokenService->currentUser($token)];
    }

    /**
     * 清除本地 Redis 中保存的登录令牌。
     *
     * @param  string  $token  上游访问令牌
     * @return void
     */
    public function logout(string $token): void
    {
        $this->authTokenService->revoke($token);
    }

    private function ensureSuccess(ExternalAuthResult $result, string $fallbackMessage): void
    {
        ResponseHelper::errorIf(
            ! $result->success,
            $result->message !== null && $result->message !== '' ? $result->message : $fallbackMessage,
            $this->resolveApiCode($result->code),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: string, expires_in: int, member_types: array<int, string>}
     */
    private function normalizePrepareData(array $data): array
    {
        $ticket = (string) ($data['ticket'] ?? '');
        $memberTypes = $data['memberTypes'] ?? $data['member_types'] ?? [];
        $memberTypes = is_array($memberTypes)
            ? array_values(array_filter(array_map(static fn (mixed $type): string => (string) $type, $memberTypes)))
            : [];

        ResponseHelper::errorIf($ticket === '', '登录校验响应缺少票据', ApiCode::ERROR);

        return [
            'ticket' => $ticket,
            'expires_in' => max(
                1,
                (int) ($data['expiresIn'] ?? $data['expires_in'] ?? self::DEFAULT_TICKET_TTL_SECONDS),
            ),
            'member_types' => $memberTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTokenData(array $data, string $memberType): array
    {
        $token = (string) ($data['accessToken'] ?? $data['access_token'] ?? '');
        $expiresIn = max(
            1,
            (int) ($data['expiresIn']
                ?? $data['expires_in']
                ?? config('services.member_auth.token_ttl_seconds', 172800)),
        );

        return [
            'token' => $token,
            'token_type' => (string) ($data['tokenType'] ?? $data['token_type'] ?? 'Bearer'),
            'expires_in' => $expiresIn,
            'refresh_token' => $data['refreshToken'] ?? $data['refresh_token'] ?? null,
            'member_type' => $memberType,
            'password_change_required' => $data['passwordChangeRequired'] ?? $data['password_change_required'] ?? null,
            'password_change_reason' => $data['passwordChangeReason'] ?? $data['password_change_reason'] ?? null,
            'virtual_login_confirmation_required' => $data['virtualLoginConfirmationRequired']
                ?? $data['virtual_login_confirmation_required']
                ?? false,
            'virtual_login_confirmation_ticket' => $data['virtualLoginConfirmationTicket']
                ?? $data['virtual_login_confirmation_ticket']
                ?? null,
            'virtual_login_confirmation_expires_in' => $data['virtualLoginConfirmationExpiresIn']
                ?? $data['virtual_login_confirmation_expires_in']
                ?? null,
            'user' => is_array($data['user'] ?? null) ? $data['user'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $externalData
     * @param  array<string, mixed>  $normalizedData
     * @return array<string, mixed>
     */
    private function storedUser(array $externalData, array $normalizedData, string $memberType): array
    {
        $user = $externalData['user'] ?? $externalData['member'] ?? $externalData['memberInfo'] ?? null;
        if (is_array($user)) {
            $user['member_type'] ??= $memberType;

            return $user;
        }

        return Arr::except($normalizedData, ['token', 'refresh_token', 'user', 'token_type', 'expires_in']) + [
            'member_type' => $memberType,
        ];
    }

    private function resolveApiCode(?string $code): ApiCode
    {
        return match ($code) {
            'PARAM_INVALID' => ApiCode::INVALID_PARAMS,
            'AUTH_FAIL',
            'SMS_CODE_EXPIRED',
            'SMS_CODE_INVALID',
            'SMS_VERIFY_LOCKED',
            'SMS_IP_LOCKED' => ApiCode::UNAUTHORIZED,
            'NOT_FOUND' => ApiCode::NOT_FOUND,
            'BIZ_CONFLICT', 'SMS_TOO_FREQUENT', 'SMS_DAILY_LIMIT', 'TENANT_UNASSIGNED' => ApiCode::CONFLICT,
            default => ApiCode::ERROR,
        };
    }
}

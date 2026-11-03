<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
        $data = $this->normalizePrepareData($result->data);

        return new AuthLoginResult(
            $data,
            $result->message !== null && $result->message !== '' ? $result->message : '登录校验成功',
        );
    }

    /** 校验短信验证码并返回待确认的身份集合，不签发访问令牌。 */
    public function prepareSmsLogin(string $mobile, string $code): AuthLoginResult
    {
        $result = $this->externalAuthClient->prepareSmsLogin($mobile, $code);
        $this->ensureSuccess($result, '验证码错误或已过期');
        $data = $this->normalizePrepareData($result->data);

        return new AuthLoginResult(
            $data,
            $result->message !== null && $result->message !== '' ? $result->message : '登录校验成功',
        );
    }

    /**
     * 查询协议清单并转换为本项目字段，协议版本和摘要保持上游原值。
     *
     * @param  string  $memberType  用户选择的身份
     * @param  string  $scene  协议场景，登录使用 LOGIN
     * @return AuthLoginResult 本项目格式的协议清单及提示
     *
     * @throws \Exception 上游协议清单查询失败时抛出
     */
    public function agreementPolicy(string $memberType, string $scene): AuthLoginResult
    {
        $result = $this->externalAuthClient->agreementPolicy($memberType, $scene);
        $this->ensureSuccess($result, '协议清单获取失败');

        return new AuthLoginResult($this->normalizeAgreementData($result->data), $result->message ?: '协议清单获取成功');
    }

    /**
     * 读取协议正文，转换元数据字段名并保留正文的块、片段和样式。
     *
     * @param  string  $versionId  清单中的协议版本 ID
     * @return AuthLoginResult 本项目格式的协议正文及提示
     *
     * @throws \Exception 上游协议正文查询失败时抛出
     */
    public function agreementVersion(string $versionId): AuthLoginResult
    {
        $result = $this->externalAuthClient->agreementVersion($versionId);
        $this->ensureSuccess($result, '协议正文获取失败');

        return new AuthLoginResult($this->normalizeAgreementData($result->data), $result->message ?: '协议正文获取成功');
    }

    /**
     * 提交所选身份及客户端确认的协议版本，成功后保存用户信息和上游令牌。
     *
     * @param  string  $ticket  登录校验票据
     * @param  string  $memberType  所选身份
     * @param  array<string, mixed>|null  $agreementConsent  同意状态、协议摘要和完整版本集合
     * @return AuthLoginResult 旧项目格式的登录结果
     *
     * @throws \Exception 上游确认失败或未返回访问令牌时抛出
     */
    public function confirmLogin(string $ticket, string $memberType, ?array $agreementConsent = null): AuthLoginResult
    {
        $result = $this->externalAuthClient->confirmLogin($ticket, $memberType, $agreementConsent);
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
     * 清除本地 Redis 中保存的登录令牌。
     *
     * @return void 从当前请求读取令牌并清除对应用户缓存
     */
    public function logout(): void
    {
        $this->authTokenService->revoke();
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeAgreementData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[Str::snake($key)] = $value;
        }
        // 版本 ID 可能超出 JavaScript 安全整数范围，整个中转流程按字符串传递。
        if (isset($normalized['version_id'])) {
            $normalized['version_id'] = (string) $normalized['version_id'];
        }
        if (isset($normalized['documents']) && is_array($normalized['documents'])) {
            $normalized['documents'] = [];
            foreach ($data['documents'] as $document) {
                $normalized['documents'][] = $this->normalizeAgreementData($document);
            }
        }

        return $normalized;
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
        $user = $externalData['user']
            ?? $externalData['member']
            ?? $externalData['memberInfo']
            ?? $externalData['userInfo']
            ?? null;
        $mobile = is_array($user)
            ? $this->extractMobile($user) ?? $this->extractMobile($externalData)
            : $this->extractMobile($externalData);
        if (is_array($user)) {
            if ($mobile !== null) {
                $user['mobile'] = $mobile;
            }
            $user['member_type'] ??= $memberType;

            return $user;
        }

        $storedUser = Arr::except($normalizedData, ['token', 'refresh_token', 'user', 'token_type', 'expires_in']) + [
            'member_type' => $memberType,
        ];
        if ($mobile !== null) {
            $storedUser['mobile'] = $mobile;
        }

        return $storedUser;
    }

    /**
     * 从登录成功响应中提取用户手机号，兼容常见上游字段名和用户对象。
     *
     * @param  array<string, mixed>  $data  上游登录成功数据
     * @return string|null 登录成功响应中的手机号
     */
    private function extractMobile(array $data): ?string
    {
        foreach (['mobile', 'phone', 'telephone'] as $field) {
            $value = $data[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['user', 'member', 'memberInfo', 'userInfo'] as $field) {
            if (is_array($data[$field] ?? null)) {
                $mobile = $this->extractMobile($data[$field]);
                if ($mobile !== null) {
                    return $mobile;
                }
            }
        }

        return null;
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
            'BIZ_CONFLICT', 'SMS_TOO_FREQUENT', 'SMS_DAILY_LIMIT', 'TENANT_UNASSIGNED',
            'AGREEMENT_NOT_ACCEPTED', 'AGREEMENT_VERSION_CHANGED', 'AGREEMENT_SET_MISMATCH' => ApiCode::CONFLICT,
            default => ApiCode::ERROR,
        };
    }
}

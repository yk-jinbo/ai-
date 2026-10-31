<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 会员认证 HTTP 客户端。
 *
 * 只负责调用上游网关和解析其 {code,msg,traceId,data} 响应，不向前端暴露上游结构。
 */
class HttpExternalAuthClient implements ExternalAuthClient
{
    private const SMS_SEND_ENDPOINT = 'sms/send';

    private const PASSWORD_PREPARE_ENDPOINT = 'member/login/prepare';

    private const SMS_PREPARE_ENDPOINT = 'member/login/sms/prepare';

    private const CONFIRM_ENDPOINT = 'member/login/confirm';

    /**
     * 转发登录短信请求。
     *
     * @param  string  $mobile  接收短信的手机号
     * @return ExternalAuthResult 上游标准化响应
     */
    public function sendSms(string $mobile): ExternalAuthResult
    {
        return $this->request(self::SMS_SEND_ENDPOINT, [
            'mobile' => $mobile,
            'scene' => 'LOGIN',
        ]);
    }

    /**
     * 转发账号密码登录校验。
     *
     * @param  string  $username  账号或手机号
     * @param  string  $password  登录密码
     * @return ExternalAuthResult 上游标准化响应
     */
    public function preparePasswordLogin(string $username, string $password): ExternalAuthResult
    {
        return $this->request(self::PASSWORD_PREPARE_ENDPOINT, [
            'scene' => 'LOGIN',
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * 转发短信验证码登录校验。
     *
     * @param  string  $mobile  登录手机号
     * @param  string  $code  短信验证码
     * @return ExternalAuthResult 上游标准化响应
     */
    public function prepareSmsLogin(string $mobile, string $code): ExternalAuthResult
    {
        return $this->request(self::SMS_PREPARE_ENDPOINT, [
            'scene' => 'LOGIN',
            'mobile' => $mobile,
            'code' => $code,
        ]);
    }

    /**
     * 转发身份选择和协议确认。
     *
     * @param  string  $ticket  登录校验票据
     * @param  string  $memberType  选中的会员身份
     * @param  bool  $agreementAccepted  是否接受协议
     * @return ExternalAuthResult 上游标准化响应
     */
    public function confirmLogin(string $ticket, string $memberType, bool $agreementAccepted): ExternalAuthResult
    {
        return $this->request(self::CONFIRM_ENDPOINT, [
            'ticket' => $ticket,
            'memberType' => $memberType,
            'agreementAccepted' => $agreementAccepted,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(string $endpoint, array $payload): ExternalAuthResult
    {
        $url = $this->url($endpoint);
        if ($url === null) {
            Log::error('会员认证上游地址未配置', ['endpoint' => $endpoint]);

            return new ExternalAuthResult(false, 'INTERNAL_ERROR', '认证服务未配置');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) config('services.member_auth.connect_timeout', 3))
                ->timeout((int) config('services.member_auth.timeout', 10))
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::error('会员认证上游连接失败', [
                'endpoint' => $endpoint,
                'exception' => $exception->getMessage(),
            ]);

            return new ExternalAuthResult(false, 'INTERNAL_ERROR', '认证服务暂时不可用');
        }

        return $this->parseResponse($response, $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseResponse(Response $response, string $endpoint, array $payload): ExternalAuthResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $code = is_scalar($body['code'] ?? null) ? trim((string) $body['code']) : null;
        $message = is_scalar($body['msg'] ?? null) ? trim((string) $body['msg']) : null;
        $traceId = is_scalar($body['traceId'] ?? null) ? trim((string) $body['traceId']) : null;
        $success = $response->successful() && $code === 'OK';

        if (! $success) {
            Log::warning('会员认证上游返回业务失败', [
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
                'code' => $code,
                'trace_id' => $traceId,
                'request' => $this->sanitizePayload($payload),
            ]);
        }

        return new ExternalAuthResult($success, $code, $message, $data, $traceId);
    }

    private function url(string $endpoint): ?string
    {
        $baseUrl = rtrim((string) config('services.member_auth.base_url'), '/');
        if ($baseUrl === '') {
            return null;
        }

        $gatewayPrefix = trim((string) config('services.member_auth.gateway_prefix', 'api-iam'), '/');
        $apiPrefix = trim((string) config('services.member_auth.api_prefix', 'api/v1'), '/');
        $path = implode('/', array_filter([
            $gatewayPrefix,
            $apiPrefix,
            trim($endpoint, '/'),
        ], static fn (string $part): bool => $part !== ''));

        return $baseUrl.'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['username', 'mobile'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = Str::mask((string) $payload[$field], '*', 3, 4);
            }
        }

        foreach (['password', 'code', 'ticket'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED]';
            }
        }

        return $payload;
    }
}

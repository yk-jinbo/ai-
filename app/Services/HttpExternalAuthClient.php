<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 会员认证 HTTP 客户端。
 *
 * 只负责调用上游网关和解析其 {code,msg,traceId,data} 响应，不向前端暴露上游结构。
 */
class HttpExternalAuthClient implements ExternalAuthClient
{
    private const HTTP_METHOD = 'POST';

    private const MAX_LOG_STRING_LENGTH = 2000;

    /** @var array<int, string> */
    private const SENSITIVE_FIELDS = [
        'password',
        'code',
        'ticket',
        'accessToken',
        'access_token',
        'refreshToken',
        'refresh_token',
        'authorization',
        'token',
    ];

    private ?string $requestId = null;

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
        $startedAt = microtime(true);
        $requestId = $this->requestId();
        $url = $this->url($endpoint);
        if ($url === null) {
            $this->logFailure(
                failureType: 'configuration',
                endpoint: $endpoint,
                url: null,
                requestId: $requestId,
                startedAt: $startedAt,
                payload: $payload,
                upstreamMessage: '会员认证上游地址未配置',
            );

            return new ExternalAuthResult(false, 'INTERNAL_ERROR', '认证服务未配置');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-Request-Id' => $requestId])
                ->connectTimeout((int) config('services.member_auth.connect_timeout', 3))
                ->timeout((int) config('services.member_auth.timeout', 10))
                ->post($url, $payload);
        } catch (Throwable $exception) {
            $this->logFailure(
                failureType: $exception instanceof ConnectionException ? 'connection' : 'exception',
                endpoint: $endpoint,
                url: $url,
                requestId: $requestId,
                startedAt: $startedAt,
                payload: $payload,
                exception: $exception,
            );

            return new ExternalAuthResult(false, 'INTERNAL_ERROR', '认证服务暂时不可用');
        }

        return $this->parseResponse($response, $endpoint, $url, $requestId, $startedAt, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseResponse(
        Response $response,
        string $endpoint,
        string $url,
        string $requestId,
        float $startedAt,
        array $payload,
    ): ExternalAuthResult
    {
        $decodedBody = $response->json();
        $body = is_array($decodedBody) ? $decodedBody : [];
        $responseBodyForLog = is_array($decodedBody)
            ? $body
            : ['raw' => $this->sanitizeText($response->body())];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $code = is_scalar($body['code'] ?? null) ? trim((string) $body['code']) : null;
        $message = is_scalar($body['msg'] ?? null) ? trim((string) $body['msg']) : null;
        $traceId = is_scalar($body['traceId'] ?? null)
            ? trim((string) $body['traceId'])
            : trim((string) ($response->header('X-Trace-Id') ?? $response->header('traceId') ?? ''));
        $traceId = $traceId === '' ? null : $traceId;
        $success = $response->successful() && $code === 'OK';

        if (! $success) {
            $this->logFailure(
                failureType: ! $response->successful()
                    ? 'http'
                    : ($code === null ? 'invalid_response' : 'upstream_business'),
                endpoint: $endpoint,
                url: $url,
                requestId: $requestId,
                startedAt: $startedAt,
                payload: $payload,
                responseBody: $responseBodyForLog,
                httpStatus: $response->status(),
                upstreamCode: $code,
                upstreamMessage: $message,
                upstreamTraceId: $traceId,
            );
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

    private function requestId(): string
    {
        $requestId = trim((string) request()->header('X-Request-Id'));
        $requestId = preg_replace('/[^A-Za-z0-9._:-]/', '', $requestId) ?? '';
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $this->requestId = $this->truncate($requestId, 128) ?? (string) Str::uuid();

        return (string) $this->requestId;
    }

    /**
     * 只在上游调用失败时写入独立日志，避免成功请求产生噪声。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $responseBody
     */
    private function logFailure(
        string $failureType,
        string $endpoint,
        ?string $url,
        string $requestId,
        float $startedAt,
        array $payload,
        ?array $responseBody = null,
        ?int $httpStatus = null,
        ?string $upstreamCode = null,
        ?string $upstreamMessage = null,
        ?string $upstreamTraceId = null,
        ?Throwable $exception = null,
    ): void {
        $context = [
            'event' => 'external_auth_failure',
            'failure_type' => $failureType,
            'request_id' => $requestId,
            'endpoint' => $endpoint,
            'request_url' => $url === null ? null : $this->sanitizeUrl($url),
            'http_method' => self::HTTP_METHOD,
            'duration_ms' => $this->durationMs($startedAt),
            'http_status' => $httpStatus,
            'upstream_code' => $upstreamCode,
            'upstream_message' => $this->sanitizeText($upstreamMessage),
            'upstream_trace_id' => $this->truncate($upstreamTraceId, 256),
            'request_summary' => $this->requestSummary($payload),
            'response_summary' => $responseBody === null ? null : $this->responseSummary($responseBody),
            'local_request' => $this->requestContext(),
        ];

        if ($exception !== null) {
            $context['exception'] = [
                'class' => $exception::class,
                'message' => $this->sanitizeText($exception->getMessage()),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        Log::channel('external_auth')->warning('external_auth_failure', $context);
    }

    /**
     * @return array<string, string|null>
     */
    private function requestContext(): array
    {
        $request = request();
        $route = $request->route();

        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => is_object($route) ? $route->getName() : null,
            'ip' => $request->ip(),
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * 记录请求字段和非敏感字段值，不记录完整请求体。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestSummary(array $payload): array
    {
        $safeFields = [];
        $redactedFields = [];
        foreach ($payload as $field => $value) {
            if ($this->isSensitiveField((string) $field)) {
                $redactedFields[] = (string) $field;
                continue;
            }

            $safeFields[(string) $field] = $this->sanitizeValue($value, (string) $field);
        }

        return [
            'fields' => array_map(static fn (mixed $field): string => (string) $field, array_keys($payload)),
            'safe_fields' => $safeFields,
            'redacted_fields' => $redactedFields,
        ];
    }

    /**
     * 只保留响应结构摘要，避免把用户数据或完整响应体写入日志。
     *
     * @param  array<string, mixed>  $responseBody
     * @return array<string, mixed>
     */
    private function responseSummary(array $responseBody): array
    {
        $summary = [
            'keys' => array_map(static fn (mixed $key): string => (string) $key, array_keys($responseBody)),
        ];

        if (is_array($responseBody['data'] ?? null)) {
            $summary['data_keys'] = array_map(
                static fn (mixed $key): string => (string) $key,
                array_keys($responseBody['data']),
            );
        }

        if (isset($responseBody['raw']) && is_string($responseBody['raw'])) {
            $summary['raw_length'] = strlen($responseBody['raw']);
        }

        return $summary;
    }

    private function sanitizeUrl(string $url): string
    {
        $url = preg_replace('#^(https?://)(?:[^/@]+(?::[^/@]*)?@)#i', '$1[REDACTED]@', $url) ?? $url;

        return (string) $this->sanitizeText($url);
    }

    private function truncate(?string $value, int $limit = self::MAX_LOG_STRING_LENGTH): ?string
    {
        if ($value === null || strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, max(0, $limit - 3)).'...';
    }

    private function sanitizeValue(mixed $value, ?string $field = null, int $depth = 0): mixed
    {
        if ($field !== null && $this->isSensitiveField($field)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            if ($depth >= 6) {
                return '[TRUNCATED]';
            }

            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeValue($item, is_string($key) ? $key : null, $depth + 1);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            if ($field !== null && in_array(strtolower($field), ['username', 'mobile'], true)) {
                return Str::mask($value, '*', 3, 4);
            }

            return $this->sanitizeText($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return $this->truncate(get_debug_type($value));
    }

    private function isSensitiveField(string $field): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $field));

        foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
            if ($normalized === strtolower((string) preg_replace('/[^a-z0-9]/i', '', $sensitiveField))) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $fields = implode('|', array_map(
            static fn (string $field): string => preg_quote($field, '/'),
            self::SENSITIVE_FIELDS,
        ));
        $pattern = "/([\"']?(?:{$fields})[\"']?\\s*[:=]\\s*)(\"[^\"]*\"|'[^']*'|[^,;&}\\s]+)/i";
        $sanitized = preg_replace($pattern, '$1[REDACTED]', $value);

        return $this->truncate(is_string($sanitized) ? $sanitized : $value);
    }
}

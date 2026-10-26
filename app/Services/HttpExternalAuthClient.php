<?php

namespace App\Services;

use App\Caches\CacheManager;
use App\Enums\AuthLoginType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HttpExternalAuthClient implements ExternalAuthClient
{
    private const PASSWORD_LOGIN_ENDPOINT = '/login';

    private const SMS_LOGIN_ENDPOINT = '/login/sms';

    private const SMS_SEND_ENDPOINT = '/sms/send';

    private const BIZ_KEYS_ENDPOINT = '/biz-keys';

    private const BIZ_KEYS_CACHE_SCOPE = 'open-auth-biz-keys';

    private const BIZ_KEYS_CACHE_IDENTIFIER = 'mapping';

    private const BIZ_KEYS_CACHE_TTL_SECONDS = 86400;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private CacheManager $cacheManager,
    ) {}

    /**
     * 调用外部认证接口验证登录信息。
     */
    public function login(AuthLoginType $loginType, array $loginInfo): ExternalAuthResult
    {
        return match ($loginType) {
            AuthLoginType::PASSWORD => $this->request(self::PASSWORD_LOGIN_ENDPOINT, [
                'username' => (string) ($loginInfo['account'] ?? ''),
                'password' => (string) ($loginInfo['password'] ?? ''),
                'memberType' => 'NORMAL',
            ]),
            AuthLoginType::SMS => $this->request(self::SMS_LOGIN_ENDPOINT, [
                'mobile' => (string) ($loginInfo['mobile'] ?? ''),
                'code' => (string) ($loginInfo['code'] ?? ''),
                'memberType' => 'NORMAL',
            ]),
        };
    }

    /**
     * 调用外部接口发送登录验证码。
     */
    public function sendSms(string $mobile): ExternalAuthResult
    {
        return $this->request(self::SMS_SEND_ENDPOINT, ['mobile' => $mobile]);
    }

    /**
     * 发起外部请求并解析业务结果。
     */
    private function request(string $endpoint, array $payload): ExternalAuthResult
    {
        $baseUrl = rtrim((string) config('services.open_auth.base_url'), '/');
        if ($baseUrl === '') {
            Log::error('外部认证请求失败：未配置接口基址', [
                'method' => 'POST',
                'endpoint' => $endpoint,
                'request' => $this->sanitizePayload($payload),
            ]);

            return new ExternalAuthResult(false);
        }

        $url = $baseUrl.$endpoint;

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::error('外部认证请求连接失败', [
                'method' => 'POST',
                'url' => $url,
                'request' => $this->sanitizePayload($payload),
                'exception' => $exception->getMessage(),
            ]);

            return new ExternalAuthResult(false);
        }

        $body = $response->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $bizKey = $this->extractBizKey($body, $data);
        $success = $response->successful() && ($data['success'] ?? null) === true;
        $message = $this->resolveBizKeyMessage($bizKey);

        if (! $success) {
            Log::warning('外部认证请求返回失败', [
                'method' => 'POST',
                'url' => $url,
                'request' => $this->sanitizePayload($payload),
                'http_status' => $response->status(),
                'biz_key' => $bizKey,
                'success' => $data['success'] ?? null,
                'trace_id' => is_array($body) ? ($body['traceId'] ?? null) : null,
            ]);
        }

        return new ExternalAuthResult($success, $bizKey, $message);
    }

    /**
     * 解析标准响应中的 biz_key，并兼容上游异常响应的顶层 code。
     */
    private function extractBizKey(mixed $body, array $data): ?string
    {
        $candidate = $data['biz_key'] ?? (is_array($body) ? ($body['code'] ?? null) : null);
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        return $candidate === '' ? null : $candidate;
    }

    /**
     * 从缓存或上游字典中解析业务提示。
     */
    private function resolveBizKeyMessage(?string $bizKey): ?string
    {
        if ($bizKey === null) {
            return null;
        }

        $message = $this->loadBizKeyMessages()[$bizKey] ?? null;
        if (! is_string($message)) {
            return null;
        }

        $message = trim($message);

        return $message === '' ? null : $message;
    }

    /**
     * 字典变化频率低，使用 Redis 缓存避免每次登录都请求上游。
     *
     * @return array<string, string>
     */
    private function loadBizKeyMessages(): array
    {
        $baseUrl = rtrim((string) config('services.open_auth.base_url'), '/');
        if ($baseUrl === '') {
            return [];
        }

        $scope = null;
        try {
            $scope = $this->cacheManager->scope(self::BIZ_KEYS_CACHE_SCOPE);
            $cached = $scope->connection()->get($scope->key(self::BIZ_KEYS_CACHE_IDENTIFIER));
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
                $messages = $this->normalizeBizKeyMessages($decoded);
                if ($messages !== []) {
                    return $messages;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('外部认证业务字典缓存读取失败', [
                'exception' => $exception->getMessage(),
            ]);
        }

        $url = $baseUrl.self::BIZ_KEYS_ENDPOINT;
        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('外部认证业务字典请求连接失败', [
                'method' => 'GET',
                'url' => $url,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        $messages = $this->normalizeBizKeyMessages($response->json('data'));
        if (! $response->successful() || $messages === []) {
            Log::warning('外部认证业务字典请求返回失败', [
                'method' => 'GET',
                'url' => $url,
                'http_status' => $response->status(),
            ]);

            return [];
        }

        if ($scope !== null) {
            try {
                $scope->connection()->setex(
                    $scope->key(self::BIZ_KEYS_CACHE_IDENTIFIER),
                    self::BIZ_KEYS_CACHE_TTL_SECONDS,
                    json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                );
            } catch (Throwable $exception) {
                Log::warning('外部认证业务字典缓存写入失败', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $messages;
    }

    /**
     * 仅接受扁平的字符串键值映射，避免把业务响应误当成字典。
     *
     * @return array<string, string>
     */
    private function normalizeBizKeyMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        if (array_key_exists('biz_key', $messages)
            || array_key_exists('success', $messages)
            || array_key_exists('sign', $messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $key => $message) {
            if (! is_string($key) || ! is_string($message)) {
                continue;
            }

            $key = trim($key);
            $message = trim($message);
            if ($key !== '' && $message !== '') {
                $normalized[$key] = $message;
            }
        }

        return $normalized;
    }

    /**
     * 对日志中的登录信息脱敏。
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['username', 'mobile'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = Str::mask((string) $payload[$field], '*', 3, 4);
            }
        }

        foreach (['password', 'code'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED]';
            }
        }

        return $payload;
    }
}

<?php

namespace App\Services;

use App\Enums\ExternalHttpFailureType;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 外部请求失败日志服务。
 * 只记录定位所需的上下文和响应结构，统一脱敏，禁止记录完整请求体和凭证。
 */
class ExternalHttpLogger
{
    private const MAX_TEXT_BYTES = 2000;

    private const MAX_FIELDS = 50;

    private const SENSITIVE_NAME_PATTERN = '(?:password|passwd|pwd|code|sms[_-]?code|verification[_-]?code|authorization|cookie|set[_-]?cookie|api[_-]?key|[a-z0-9_-]*(?:token|ticket|secret))';

    private const SAFE_FIELDS = ['scene', 'memberType', 'agreementAccepted', 'page', 'page_size', 'per_page', 'limit'];

    /**
     * 记录一次外部请求失败，不将异常对象交给日志器以免堆栈泄露请求内容。
     *
     * @param  ExternalHttpFailureType  $type  失败分类
     * @param  array<string, mixed>  $request  通用客户端提供的请求上下文
     * @param  Response|null  $response  已收到的上游响应，连接失败时为空
     * @param  Throwable|null  $exception  原始异常，仅记录脱敏消息与源码位置
     * @return void 失败上下文写入独立日志通道
     */
    public function failure(ExternalHttpFailureType $type, array $request, ?Response $response = null, ?Throwable $exception = null): void
    {
        $params = $this->parameters($request);
        $body = $response === null ? null : json_decode($response->body(), true);
        // 响应顶层 code 是业务状态码，请求中的 code 才是需要隐藏的验证码。
        $responseSecrets = is_array($body) ? array_diff_key($body, array_flip(['code', 'msg', 'message', 'traceId', 'trace_id'])) : [];
        $secrets = $this->secrets([$params, $request['options'], $responseSecrets]);
        $context = $this->requestContext($request, $type, $params, $secrets);
        $context += $this->responseContext($response, $body, $secrets);
        $context['local_request'] = $this->localRequest($secrets);

        if ($exception !== null) {
            $context['exception'] = [
                'class' => $exception::class,
                'message' => $this->text($exception->getMessage(), $secrets),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        Log::channel('external_http')->warning('external_http_failure', $context);
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $params @param list<string> $secrets @return array<string, mixed> */
    private function requestContext(array $request, ExternalHttpFailureType $type, array $params, array $secrets): array
    {
        return [
            'event' => 'external_http_failure',
            'failure_type' => $type->value,
            'failure_message' => $type->label(),
            'service' => $this->text($request['service'], $secrets),
            'gateway' => $request['gateway'],
            'request_id' => $request['request_id'],
            'endpoint' => $this->text((string) parse_url($request['url'], PHP_URL_PATH), $secrets),
            'request_url' => $this->url($request, $secrets),
            'http_method' => $request['method'],
            'duration_ms' => max(0, (int) round((hrtime(true) - $request['started_at']) / 1_000_000)),
            'request_summary' => $this->requestSummary($params),
        ];
    }

    /** @param list<string> $secrets @return array<string, mixed> */
    private function responseContext(?Response $response, mixed $body, array $secrets): array
    {
        $body = is_array($body) ? $body : [];
        $traceId = $body['traceId'] ?? $body['trace_id'] ?? $response?->header('X-Trace-Id') ?? $response?->header('traceId');

        return [
            'http_status' => $response?->status(),
            'upstream_code' => $this->text($body['code'] ?? null, $secrets),
            'upstream_message' => $this->text($body['msg'] ?? $body['message'] ?? null, $secrets),
            'upstream_trace_id' => $this->text($traceId, $secrets),
            'response_summary' => $response === null ? null : [
                'content_type' => $this->text($response->header('Content-Type'), $secrets),
                'body_bytes' => strlen($response->body()),
                'keys' => $this->fieldNames($body),
                'data_keys' => is_array($body['data'] ?? null) ? $this->fieldNames($body['data']) : [],
            ],
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function parameters(array $request): array
    {
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        $params = array_replace($query, $request['data']);
        foreach (['query', 'json', 'form_params'] as $key) {
            if (is_array($request['options'][$key] ?? null)) {
                $params = array_replace($params, $request['options'][$key]);
            }
        }

        return $params;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function requestSummary(array $params): array
    {
        $safeFields = [];
        foreach ($params as $key => $value) {
            if (in_array($key, self::SAFE_FIELDS, true) && is_scalar($value)) {
                $safeFields[$key] = $this->text($value);
            }
            if (in_array($key, ['username', 'mobile'], true) && is_string($value)) {
                $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
                $safeFields[$key] = $length > 7 ? Str::mask($value, '*', 3, $length - 7) : '[REDACTED]';
            }
        }

        return ['fields' => $this->fieldNames($params), 'safe_fields' => $safeFields];
    }

    /** @param array<array-key, mixed> $values @return list<string> */
    private function fieldNames(array $values): array
    {
        $names = [];
        foreach (array_slice(array_keys($values), 0, self::MAX_FIELDS) as $key) {
            $names[] = $this->text($key);
        }

        return $names;
    }

    /** @param array<array-key, mixed> $values @return list<string> */
    private function secrets(array $values, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [];
        }

        $secrets = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $secrets = array_merge($secrets, $this->secrets($value, $depth + 1));

                continue;
            }
            if (is_string($key) && preg_match('/\A'.self::SENSITIVE_NAME_PATTERN.'\z/i', $key) && is_scalar($value) && (string) $value !== '') {
                $secrets[] = (string) $value;
            }
        }

        // 先替换长值，避免较短的敏感值让同一凭证只能被部分替换。
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique($secrets));
    }

    /** @param list<string> $secrets */
    private function text(mixed $value, array $secrets = []): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = str_replace($secrets, '[REDACTED]', (string) $value);
        // 异常消息可能包含凭证、URL 用户信息或多行文本，必须先清洗再截断。
        $patterns = [
            '~(https?://)[^/@\s]+@~i' => '$1[REDACTED]@',
            '/\b(Bearer|Basic)\s+[^\s,;"\']+/i' => '$1 [REDACTED]',
            '/(["\']?'.self::SENSITIVE_NAME_PATTERN.'["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&}]+)/i' => '$1[REDACTED]',
            '/(?<!\d)(1[3-9]\d)(\d{4})(\d{4})(?!\d)/' => '$1****$3',
            '/[\r\n\x00-\x1F\x7F]+/' => ' ',
        ];
        $text = preg_replace(array_keys($patterns), array_values($patterns), $text) ?? '[REDACTED]';

        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, self::MAX_TEXT_BYTES, 'UTF-8');
        }

        return substr($text, 0, self::MAX_TEXT_BYTES);
    }

    /** @param array<string, mixed> $request @param list<string> $secrets */
    private function url(array $request, array $secrets): ?string
    {
        $parts = parse_url($request['url']);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '[INVALID_URL]';
        }

        $safeUrl = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        $safeUrl .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $safeUrl .= $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        if (in_array($request['method'], ['GET', 'HEAD'], true)) {
            $query = array_replace($query, $request['data']);
        }
        if (is_array($request['options']['query'] ?? null)) {
            $query = array_replace($query, $request['options']['query']);
        }
        foreach ($query as $key => $value) {
            $query[$key] = in_array($key, self::SAFE_FIELDS, true) && is_scalar($value) ? $value : '[REDACTED]';
        }
        if ($query !== []) {
            $safeUrl .= '?'.http_build_query($query);
        }

        return $this->text($safeUrl, $secrets);
    }

    /** @param list<string> $secrets @return array<string, mixed>|null */
    private function localRequest(array $secrets): ?array
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        $route = $request->route();

        return [
            'method' => $request->method(),
            'path' => $this->text($request->path(), $secrets),
            'route' => $route?->getName(),
            'action' => $route?->getActionName(),
        ];
    }
}

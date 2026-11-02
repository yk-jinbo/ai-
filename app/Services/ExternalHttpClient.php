<?php

namespace App\Services;

use App\Enums\ExternalGateway;
use App\Enums\ExternalHttpFailureType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * 通用外部 HTTP 请求入口。
 * 统一网关选择、请求标识、超时和失败日志，具体接口的业务成功条件由调用方提供。
 */
class ExternalHttpClient
{
    private const REQUEST_ID_ATTRIBUTE = 'external_http_request_id';

    /**
     * 注入失败日志服务，使业务客户端无需重复处理日志。
     *
     * @param  ExternalHttpLogger  $logger  外部请求失败日志服务
     * @return void
     */
    public function __construct(private ExternalHttpLogger $logger) {}

    /**
     * 发起 GET 请求，将参数作为查询字符串并自动记录失败。
     *
     * @param  string  $url  网关内的相对路径，或兼容已有调用的完整上游 URL
     * @param  array<string, mixed>  $data  查询参数
     * @param  callable(Response): bool|null  $isSuccessful  业务成功判断，省略时只判断 HTTP 状态
     * @param  array<string, mixed>  $options  Laravel/Guzzle 选项，如 headers、timeout
     * @param  string  $service  日志中的业务服务名
     * @param  ExternalGateway  $gateway  相对路径使用的网关，默认原业务网关
     * @return Response 上游原始响应，HTTP 或业务失败仍交给调用方处理
     *
     * @throws Throwable 连接、配置或成功判断异常，记录一次后继续抛出
     */
    public function get(string $url, array $data = [], ?callable $isSuccessful = null, array $options = [], string $service = 'external', ExternalGateway $gateway = ExternalGateway::DEFAULT): Response
    {
        return $this->request('GET', $url, $data, $isSuccessful, $options, $service, $gateway);
    }

    /**
     * 发起 POST 请求，默认以 JSON 传参并自动记录失败。
     *
     * @param  string  $url  网关内的相对路径，或兼容已有调用的完整上游 URL
     * @param  array<string, mixed>  $data  JSON 参数
     * @param  callable(Response): bool|null  $isSuccessful  业务成功判断，省略时只判断 HTTP 状态
     * @param  array<string, mixed>  $options  Laravel/Guzzle 选项，如 headers、timeout
     * @param  string  $service  日志中的业务服务名
     * @param  ExternalGateway  $gateway  相对路径使用的网关，默认原业务网关
     * @return Response 上游原始响应，HTTP 或业务失败仍交给调用方处理
     *
     * @throws Throwable 连接、配置或成功判断异常，记录一次后继续抛出
     */
    public function post(string $url, array $data = [], ?callable $isSuccessful = null, array $options = [], string $service = 'external', ExternalGateway $gateway = ExternalGateway::DEFAULT): Response
    {
        return $this->request('POST', $url, $data, $isSuccessful, $options, $service, $gateway);
    }

    /**
     * 发起外部请求，成功不写日志，失败统一记录且不吞掉异常。
     *
     * @param  string  $method  HTTP 方法，支持 GET、POST、PUT、PATCH、DELETE 等
     * @param  string  $url  网关内的相对路径，或兼容已有调用的完整上游 URL
     * @param  array<string, mixed>  $data  GET/HEAD 为查询参数，其他方法默认为 JSON
     * @param  callable(Response): bool|null  $isSuccessful  业务成功判断，省略时只判断 HTTP 状态
     * @param  array<string, mixed>  $options  Laravel/Guzzle 选项，可用 form_params、multipart 覆盖默认 JSON
     * @param  string  $service  日志中的业务服务名
     * @param  ExternalGateway  $gateway  相对路径使用的网关，默认原业务网关
     * @return Response 上游原始响应
     *
     * @throws Throwable 配置、连接、发送或业务成功判断异常，记录一次后继续抛出
     */
    public function request(string $method, string $url, array $data = [], ?callable $isSuccessful = null, array $options = [], string $service = 'external', ExternalGateway $gateway = ExternalGateway::DEFAULT): Response
    {
        $context = $this->context($method, $url, $data, $options, $service, $gateway);
        $response = null;
        try {
            $context['url'] = $this->resolveUrl($url, $gateway);
            $this->validateUrl($context['url']);
            $response = $this->send($context, $options);
            $failure = $this->responseFailure($response, $isSuccessful);
        } catch (Throwable $exception) {
            $failure = match (true) {
                $exception instanceof ConnectionException => ExternalHttpFailureType::CONNECTION,
                $exception instanceof InvalidArgumentException && $response === null => ExternalHttpFailureType::CONFIGURATION,
                default => ExternalHttpFailureType::EXCEPTION,
            };
            $this->logger->failure($failure, $context, $response, $exception);

            throw $exception;
        }

        if ($failure !== null) {
            $this->logger->failure($failure, $context, $response);
        }

        return $response;
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $options */
    private function send(array $context, array $options): Response
    {
        $bodyOption = in_array($context['method'], ['GET', 'HEAD'], true) ? 'query' : 'json';
        if (! array_intersect(['json', 'form_params', 'multipart', 'body'], array_keys($options))) {
            $options[$bodyOption] = array_replace($options[$bodyOption] ?? [], $context['data']);
        }

        // HTTP 错误在收到响应后统一判断，避免抛异常造成重复日志或丢失响应。
        $options['http_errors'] = false;
        $options['allow_redirects'] ??= false;
        $options['headers']['X-Request-Id'] = $context['request_id'];

        return Http::acceptJson()
            ->connectTimeout((int) config('services.external_http.connect_timeout', 3))
            ->timeout((int) config('services.external_http.timeout', 10))
            ->send($context['method'], $context['url'], $options);
    }

    private function responseFailure(Response $response, ?callable $isSuccessful): ?ExternalHttpFailureType
    {
        if (! $response->successful()) {
            return ExternalHttpFailureType::HTTP;
        }

        if ($isSuccessful === null || $isSuccessful($response)) {
            return null;
        }

        return is_array($response->json())
            ? ExternalHttpFailureType::BUSINESS
            : ExternalHttpFailureType::INVALID_RESPONSE;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new InvalidArgumentException('外部接口 URL 未配置或格式错误');
        }
    }

    private function resolveUrl(string $url, ExternalGateway $gateway): string
    {
        if ($url === '' || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('外部接口 URL 不能为空或使用无协议的主机地址');
        }

        // 完整 URL 保持原有目标地址，不因默认网关变化而重写旧调用。
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        $baseUrl = rtrim(trim((string) config('services.external_http.gateways.'.$gateway->value)), '/');
        if ($baseUrl === '') {
            throw new InvalidArgumentException($gateway->label().'地址未配置');
        }
        $this->validateUrl($baseUrl);

        return $baseUrl.'/'.ltrim($url, '/');
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $options @return array<string, mixed> */
    private function context(string $method, string $url, array $data, array $options, string $service, ExternalGateway $gateway): array
    {
        return [
            'service' => $service,
            'gateway' => $gateway->value,
            'method' => strtoupper($method),
            'url' => $url,
            'data' => $data,
            'options' => $options,
            'request_id' => $this->requestId(),
            'started_at' => hrtime(true),
        ];
    }

    private function requestId(): string
    {
        if (! app()->bound('request')) {
            return (string) Str::uuid();
        }

        $request = request();
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE) ?? $request->header('X-Request-Id');
        // 严格限制可传给上游的标识；同一个本地请求的多次外部调用复用标识。
        if (! is_string($requestId) || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $requestId)) {
            $requestId = (string) Str::uuid();
        }
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }
}

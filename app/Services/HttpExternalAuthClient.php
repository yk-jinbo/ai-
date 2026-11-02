<?php

namespace App\Services;

use App\Enums\ExternalGateway;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * 会员认证 HTTP 客户端。
 * 只组装认证接口参数和解析 {code,msg,traceId,data}，通用请求及失败日志由 ExternalHttpClient 处理。
 */
class HttpExternalAuthClient implements ExternalAuthClient
{
    private const SMS_SEND_ENDPOINT = 'sms/send';

    private const PASSWORD_PREPARE_ENDPOINT = 'member/login/prepare';

    private const SMS_PREPARE_ENDPOINT = 'member/login/sms/prepare';

    private const CONFIRM_ENDPOINT = 'member/login/confirm';

    /**
     * 注入通用外部请求客户端，认证接口共用超时、请求标识和异常日志。
     *
     * @param  ExternalHttpClient  $http  通用外部请求客户端
     * @return void
     */
    public function __construct(private ExternalHttpClient $http) {}

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

    /** @param array<string, mixed> $payload */
    private function request(string $endpoint, array $payload): ExternalAuthResult
    {
        $url = $this->url($endpoint);
        try {
            $response = $this->http->post(
                url: $url ?? '',
                data: $payload,
                isSuccessful: static function (Response $response): bool {
                    $code = $response->json('code');

                    return is_scalar($code) && trim((string) $code) === 'OK';
                },
                options: [
                    'connect_timeout' => (int) config('services.member_auth.connect_timeout', 3),
                    'timeout' => (int) config('services.member_auth.timeout', 10),
                ],
                service: 'member_auth',
                gateway: ExternalGateway::IDENTITY,
            );
        } catch (Throwable $exception) {
            // 通用层已经记录；认证层只保持原有的前端错误契约，不重复写日志。
            return new ExternalAuthResult(false, 'INTERNAL_ERROR', $url === null ? '认证服务未配置' : '认证服务暂时不可用');
        }

        return $this->parseResponse($response);
    }

    private function parseResponse(Response $response): ExternalAuthResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $code = is_scalar($body['code'] ?? null) ? trim((string) $body['code']) : null;
        $message = is_scalar($body['msg'] ?? null) ? trim((string) $body['msg']) : null;
        $traceId = is_scalar($body['traceId'] ?? null)
            ? trim((string) $body['traceId'])
            : trim((string) ($response->header('X-Trace-Id') ?? $response->header('traceId') ?? ''));
        $traceId = $traceId === '' ? null : $traceId;

        return new ExternalAuthResult($response->successful() && $code === 'OK', $code, $message, $data, $traceId);
    }

    private function url(string $endpoint): ?string
    {
        $baseUrl = rtrim((string) config('services.member_auth.base_url'), '/');
        if ($baseUrl === '') {
            return null;
        }

        $path = implode('/', array_filter([
            trim((string) config('services.member_auth.gateway_prefix', 'api-iam'), '/'),
            trim((string) config('services.member_auth.api_prefix', 'api/v1'), '/'),
            trim($endpoint, '/'),
        ], static fn (string $part): bool => $part !== ''));

        return $baseUrl.'/'.$path;
    }
}

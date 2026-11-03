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

    private const AGREEMENT_POLICY_ENDPOINT = 'member/agreements/policy';

    private const AGREEMENT_VERSION_ENDPOINT = 'member/agreements/versions/';

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
     * 匿名查询所选身份的协议清单，使用认证网关和通用失败日志。
     *
     * @param  string  $memberType  用户选择的身份
     * @param  string  $scene  LOGIN 或 REGISTER 协议场景
     * @return ExternalAuthResult 上游协议版本清单
     */
    public function agreementPolicy(string $memberType, string $scene): ExternalAuthResult
    {
        return $this->request(self::AGREEMENT_POLICY_ENDPOINT, [
            'scene' => $scene,
            'memberType' => $memberType,
        ], 'GET');
    }

    /**
     * 匿名读取清单指定的已发布协议版本，保持版本 ID 的字符串精度。
     *
     * @param  string  $versionId  上游协议版本 ID
     * @return ExternalAuthResult 协议正文及版本信息
     */
    public function agreementVersion(string $versionId): ExternalAuthResult
    {
        return $this->request(self::AGREEMENT_VERSION_ENDPOINT.rawurlencode($versionId), [], 'GET');
    }

    /**
     * 转发身份选择，将本项目协议字段转换为上游 agreementConsent 对象。
     *
     * @param  string  $ticket  登录校验票据
     * @param  string  $memberType  选中的会员身份
     * @param  array{accepted: bool, policy_hash?: string, documents?: list<array{document_code: string, version_id: string}>}|null  $agreementConsent  客户端提交的完整协议同意信息
     * @return ExternalAuthResult 上游标准化响应
     */
    public function confirmLogin(string $ticket, string $memberType, ?array $agreementConsent = null): ExternalAuthResult
    {
        $payload = [
            'ticket' => $ticket,
            'memberType' => $memberType,
        ];
        // 同意状态和版本必须来自客户端；不自动获取新版本替换用户已阅读的版本。
        if ($agreementConsent !== null) {
            $payload['agreementConsent'] = $this->consentPayload($agreementConsent);
        }

        return $this->request(self::CONFIRM_ENDPOINT, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $endpoint, array $payload, string $method = 'POST'): ExternalAuthResult
    {
        $url = $this->url($endpoint);
        try {
            $response = $this->http->request(
                method: $method,
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

    /** @param array<string, mixed> $consent @return array<string, mixed> */
    private function consentPayload(array $consent): array
    {
        $payload = ['accepted' => (bool) $consent['accepted']];
        if (isset($consent['policy_hash'])) {
            $payload['policyHash'] = $consent['policy_hash'];
        }
        if (isset($consent['documents'])) {
            $payload['documents'] = [];
            foreach ($consent['documents'] as $document) {
                $payload['documents'][] = [
                    'documentCode' => $document['document_code'],
                    'versionId' => $document['version_id'],
                ];
            }
        }

        return $payload;
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

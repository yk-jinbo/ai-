<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\Response;

/**
 * 会员业务服务
 * 负责携带当前登录身份查询第三方会员身份及关联组织
 */
class MemberService
{
    private const UPSTREAM_SUCCESS_CODE = '0000';

    /**
     * 注入外部请求客户端和会员身份参数组装器
     *
     * @param  ExternalHttpClient  $http 统一外部请求客户端
     * @param  MemberIdentityParameters  $memberIdentityParameters 会员身份参数组装器
     * @return void
     */
    public function __construct(
        private ExternalHttpClient $http,
        private MemberIdentityParameters $memberIdentityParameters,
    ) {}

    /**
     * 查询当前会员身份及关联组织
     *
     * @param  array<string, mixed>  $member 当前 Token 对应的登录用户
     * @param  string  $token 当前请求中的会员 Token
     * @return array<string, mixed> 第三方返回的会员身份数据
     *
     * @throws \Throwable 第三方调用失败或响应结构异常时抛出
     */
    public function getIdentities(array $member, string $token): array
    {
        $response = $this->http->post(
            url: (string) config('services.member_identities.url'),
            data: $this->memberIdentityParameters->make($member, $token),
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'member_identities',
        );
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '会员身份及关联组织查询失败',
        );

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        ResponseHelper::errorIf(! is_array($data), '会员身份服务响应格式异常');

        return $data;
    }

    private function isSuccessful(Response $response): bool
    {
        $code = $response->json('code');

        return $response->successful()
            && is_scalar($code)
            && trim((string) $code) === self::UPSTREAM_SUCCESS_CODE;
    }
}

<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\Response;

/**
 * 会员业务服务
 * 负责携带当前登录身份查询第三方会员身份、关联组织和下属运营中心
 */
class MemberService
{
    private const UPSTREAM_SUCCESS_CODE = '0000';

    private const DEFAULT_PAGE_NO = 1;

    private const DEFAULT_PAGE_SIZE = 20;

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

    /**
     * 查询分公司下属运营中心考核列表
     *
     * 业务逻辑：组合当前会员身份和已校验的查询条件，补充分页默认值后请求第三方考核接口；日期缺省时交由第三方处理。
     *
     * @param  array<string, mixed>  $member  当前 Token 对应的登录用户
     * @param  string  $token  当前请求中的会员 Token
     * @param  array<string, mixed>  $filters  已校验的分公司、日期和分页参数
     * @return array<string, mixed> 第三方返回的运营中心考核列表
     *
     * @throws \Throwable 第三方调用失败或响应结构异常时抛出
     */
    public function operationCenters(array $member, string $token, array $filters): array
    {
        $response = $this->http->post(
            url: (string) config('services.member_operation_centers.url'),
            data: $this->operationCenterParameters($member, $token, $filters),
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'member_operation_centers',
        );

        return $this->validatedOperationCenterData($response);
    }

    /**
     * 组装第三方运营中心考核查询参数
     *
     * @param  array<string, mixed>  $member  当前 Token 对应的登录用户
     * @param  string  $token  当前请求中的会员 Token
     * @param  array<string, mixed>  $filters  已校验的运营中心查询条件
     * @return array<string, int|string> 第三方运营中心考核查询参数
     */
    private function operationCenterParameters(array $member, string $token, array $filters): array
    {
        $parameters = $this->memberIdentityParameters->make($member, $token);
        $parameters['branchCompanyId'] = (string) $filters['branchCompanyId'];
        $parameters['pageNo'] = (int) ($filters['pageNo'] ?? self::DEFAULT_PAGE_NO);
        $parameters['pageSize'] = (int) ($filters['pageSize'] ?? self::DEFAULT_PAGE_SIZE);

        if (isset($filters['asOfDate']) && $filters['asOfDate'] !== '') {
            $parameters['asOfDate'] = (string) $filters['asOfDate'];
        }

        return $parameters;
    }

    /**
     * 校验并提取第三方运营中心考核数据
     *
     * @param  Response  $response  第三方原始响应
     * @return array<string, mixed> 第三方运营中心考核业务数据
     *
     * @throws \Exception 第三方业务失败或响应格式不符合约定时抛出
     */
    private function validatedOperationCenterData(Response $response): array
    {
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '下属运营中心考核查询失败',
        );

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        ResponseHelper::errorIf(! is_array($data), '运营中心考核服务响应格式异常');

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

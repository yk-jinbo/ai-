<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\Response;

/**
 * 代理业务服务
 * 负责组装会员身份与代理收益筛选条件，并调用第三方收益接口
 */
class AgentService
{
    private const UPSTREAM_SUCCESS_CODE = '0000';

    private const DEFAULT_PAGE_NO = 1;

    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * 注入外部请求客户端和会员身份参数组装器
     *
     * @param  ExternalHttpClient  $http  统一外部请求客户端
     * @param  MemberIdentityParameters  $memberIdentityParameters  会员身份参数组装器
     * @return void
     */
    public function __construct(
        private ExternalHttpClient $http,
        private MemberIdentityParameters $memberIdentityParameters,
    ) {}

    /**
     * 查询代理收益明细
     *
     * @param  array<string, mixed>  $member  当前 Token 对应的登录用户
     * @param  string  $token  当前请求中的会员 Token
     * @param  array<string, mixed>  $filters  已校验的日期、分页与筛选条件
     * @return array<string, mixed> 第三方返回的代理收益数据
     *
     * @throws \Throwable 第三方调用失败或响应结构异常时抛出
     */
    public function getEarnings(array $member, string $token, array $filters): array
    {
        $response = $this->http->post(
            url: (string) config('services.agent_earnings.url'),
            data: $this->upstreamParameters($member, $token, $filters),
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'agent_earnings',
        );

        return $this->validatedResponseData($response);
    }

    /**
     * 组装第三方代理收益查询参数
     *
     * @param  array<string, mixed>  $member  当前 Token 对应的登录用户
     * @param  string  $token  当前请求中的会员 Token
     * @param  array<string, mixed>  $filters  已校验的收益筛选条件
     * @return array<string, int|string> 第三方代理收益查询参数
     */
    private function upstreamParameters(array $member, string $token, array $filters): array
    {
        $parameters = $this->memberIdentityParameters->make($member, $token);

        foreach (['fromDate', 'toDate'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $parameters[$field] = (string) $filters[$field];
            }
        }

        $parameters['pageNo'] = (int) ($filters['pageNo'] ?? self::DEFAULT_PAGE_NO);
        $parameters['pageSize'] = (int) ($filters['pageSize'] ?? self::DEFAULT_PAGE_SIZE);

        foreach (['agentType', 'regionCode', 'agentOrderNo'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $parameters[$field] = (string) $filters[$field];
            }
        }

        return $parameters;
    }

    /** @return bool HTTP 成功且业务码为 0000 时返回 true */
    private function isSuccessful(Response $response): bool
    {
        $code = $response->json('code');

        return $response->successful()
            && is_scalar($code)
            && trim((string) $code) === self::UPSTREAM_SUCCESS_CODE;
    }

    /**
     * 校验并提取第三方代理收益数据
     *
     * @param  Response  $response  第三方原始响应
     * @return array<string, mixed> 第三方代理收益业务数据
     *
     * @throws \Exception 第三方业务失败或响应格式不符合约定时抛出
     */
    private function validatedResponseData(Response $response): array
    {
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '代理收益查询失败',
        );

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        ResponseHelper::errorIf(! is_array($data), '代理收益服务响应格式异常');

        return $data;
    }
}

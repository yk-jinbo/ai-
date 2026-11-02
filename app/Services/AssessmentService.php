<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\Response;

/**
 * 考核业务服务
 * 负责查询第三方配置的业务类型并校验其响应结构
 */
class AssessmentService
{
    private const UPSTREAM_SUCCESS_CODE = '0000';

    private const INFO_TEST_PARAMETERS = [
        'tenantId' => 'demo',
        'mobile' => '13800000002',
        'memberType' => 'NORMAL',
    ];

    /**
     * 注入通用外部请求客户端
     *
     * @param  ExternalHttpClient  $http  统一外部请求客户端
     * @return void
     */
    public function __construct(
        private ExternalHttpClient $http,
    ) {}

    /**
     * 获取第三方考核业务类型列表
     *
     * 业务逻辑：以空 JSON 对象请求第三方接口，校验成功码和每项业务类型字段后返回列表。
     *
     * @return array<int, array{businessType: string, businessTypeName: string}> 业务类型列表
     *
     * @throws \Throwable 第三方调用失败或响应结构无效时抛出
     */
    public function getList(): array
    {
        $response = $this->http->post(
            url: (string) config('services.business_types.url'),
            data: [],
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'business_types',
        );
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '考核业务类型查询失败',
        );

        $items = $body['data']['items'] ?? null;
        ResponseHelper::errorIf(! is_array($items), '考核业务类型响应格式异常');

        return $this->normalizeItems($items);
    }

    /**
     * 按业务类型查询对应的第三方考核信息
     *
     * @param  array<string, mixed>  $parameters 考核查询参数，包含业务类型和可选业务字段
     * @return array<string, mixed> 第三方考核信息
     * @throws \Throwable 上游未配置、调用失败或响应格式异常时抛出
     */
    public function assessmentInfo(array $parameters): array
    {
        $businessType = (string) $parameters['businessType'];
        unset($parameters['businessType']);
        $url = config('services.assessment_info.urls.'.$businessType);
        ResponseHelper::errorIf(! is_string($url) || trim($url) === '', '该业务类型的第三方接口尚未配置');

        $upstreamParameters = array_merge($parameters, self::INFO_TEST_PARAMETERS);

        $response = $this->http->post(
            url: $url,
            data: $upstreamParameters,
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'assessment_info_'.$businessType,
        );
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '考核信息查询失败',
        );

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        ResponseHelper::errorIf(! is_array($data), '考核信息服务响应格式异常');

        return $data;
    }

    /**
     * 判断第三方业务请求是否成功
     *
     * @param  Response  $response  第三方原始响应
     * @return bool HTTP 请求成功且业务码为 0000 时返回 true
     */
    private function isSuccessful(Response $response): bool
    {
        $code = $response->json('code');

        return $response->successful()
            && is_scalar($code)
            && trim((string) $code) === self::UPSTREAM_SUCCESS_CODE;
    }

    /**
     * 校验并标准化业务类型列表
     *
     * @param  array<int|string, mixed>  $items  第三方业务类型原始列表
     * @return array<int, array{businessType: string, businessTypeName: string}> 校验后的业务类型列表
     *
     * @throws \Exception 列表项缺少必需字段或字段类型不正确时抛出
     */
    private function normalizeItems(array $items): array
    {
        $normalizedItems = [];

        foreach ($items as $item) {
            $isValid = is_array($item)
                && is_scalar($item['businessType'] ?? null)
                && is_scalar($item['businessTypeName'] ?? null);
            ResponseHelper::errorIf(! $isValid, '考核业务类型响应格式异常');

            $normalizedItems[] = [
                'businessType' => (string) $item['businessType'],
                'businessTypeName' => (string) $item['businessTypeName'],
            ];
        }

        return $normalizedItems;
    }
}

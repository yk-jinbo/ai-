<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

/**
 * 资产业务服务
 * 负责调用第三方资产接口、校验上游业务结果并组装稳定的资产汇总结构
 */
class AssetService
{
    private const UPSTREAM_SUCCESS_CODE = '0000';

    private const LOCAL_RESPONSE_ERROR_PREFIX = '本地校验资产服务响应失败：';

    private const DEFAULT_PAGE_NO = 1;

    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * 注入通用外部请求客户端
     *
     * 业务逻辑：复用统一的网关校验、超时、请求标识和失败日志能力。
     *
     * @param  ExternalHttpClient  $http  通用外部请求客户端
     * @param  MemberIdentityParameters  $memberIdentityParameters  第三方会员身份参数组装器
     * @return void
     */
    public function __construct(
        private ExternalHttpClient $http,
        private MemberIdentityParameters $memberIdentityParameters,
    ) {}

    /**
     * 查询并组装会员资产汇总
     *
     * 业务逻辑：使用公共资产参数组装方法发送报文，确认业务成功后仅返回约定资产字段。
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @return Collection<string, array<int, array{assetType: string, obtainedQuantity: string|null, visible: bool|null}>> 标准化资产汇总
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时抛出
     */
    public function summary(array $member, string $token): Collection
    {
        $summaryParameters = $this->summaryParameters($member, $token);
        $response = $this->http->post(
            url: (string) config('services.asset_summary.url'),
            data: $summaryParameters,
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'asset_summary',
        );

        $responseData = $this->validatedResponseData($response);
        ResponseHelper::errorIf(
            ! is_array($responseData['assets'] ?? null),
            self::LOCAL_RESPONSE_ERROR_PREFIX.'assets 必须是数组',
        );

        return collect([
            'assets' => $this->normalizeAssets($responseData['assets']),
        ]);
    }

    /**
     * 组装汇总接口身份参数。
     *
     * 业务逻辑：身份字段和令牌来自当前登录会话，统计日期由服务端生成。
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @return array{token: string, mobile: string, memberType: string, asOfDate: string} 第三方汇总参数
     */
    private function summaryParameters(array $member, string $token): array
    {
        return array_merge(
            $this->memberIdentityParameters->make($member, $token),
            ['asOfDate' => now()->toDateString()],
        );
    }

    /**
     * 查询并组装会员资产流水
     *
     * 业务逻辑：组合联调身份、日期范围、分页和可选筛选项，查询第三方资产流水。
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @param  array<string, mixed>  $filters  已校验的资产流水筛选条件
     * @return Collection<string, mixed> 标准化资产余额、流水和分页数据
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时抛出
     */
    public function records(array $member, string $token, array $filters): Collection
    {
        $response = $this->http->post(
            url: (string) config('services.asset_records.url'),
            data: $this->recordsUpstreamParameters($member, $token, $filters),
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'asset_records',
        );

        return $this->normalizeRecordData($this->validatedResponseData($response));
    }

    /**
     * 查询指定机构的资产净流入记录
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @param  array<string, mixed>  $filters  已校验的净流入记录筛选条件
     * @return Collection<string, mixed> 第三方净流入记录数据
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时抛出
     */
    public function netInflowRecords(array $member, string $token, array $filters): Collection
    {
        $response = $this->http->post(
            url: (string) config('services.asset_net_inflow_records.url'),
            data: $this->netInflowRecordParameters($member, $token, $filters),
            isSuccessful: fn (Response $response): bool => $this->isSuccessful($response),
            service: 'asset_net_inflow_records',
        );

        return collect($this->validatedResponseData($response));
    }

    /**
     * 组装第三方资产净流入记录查询参数
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @param  array<string, mixed>  $filters  已校验的筛选条件
     * @return array<string, int|string> 第三方净流入记录查询参数
     */
    private function netInflowRecordParameters(array $member, string $token, array $filters): array
    {
        $parameters = array_merge($this->memberIdentityParameters->make($member, $token), [
            'assessmentType' => (string) $filters['assessmentType'],
            'orgId' => (string) $filters['orgId'],
            'periodType' => (string) $filters['periodType'],
        ]);

        foreach (['asOfDate', 'fromDate', 'toDate'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $parameters[$field] = (string) $filters[$field];
            }
        }

        $parameters['pageNo'] = (int) ($filters['pageNo'] ?? self::DEFAULT_PAGE_NO);
        $parameters['pageSize'] = (int) ($filters['pageSize'] ?? self::DEFAULT_PAGE_SIZE);

        return $parameters;
    }

    /**
     * 组装第三方资产流水查询参数
     *
     * @param  array<string, mixed>  $member  当前令牌对应的登录用户信息
     * @param  string  $token  当前请求中的上游会员令牌
     * @param  array<string, mixed>  $filters  已校验的资产流水筛选条件
     * @return array<string, int|string> 第三方资产流水查询参数
     */
    private function recordsUpstreamParameters(array $member, string $token, array $filters): array
    {
        $parameters = $this->memberIdentityParameters->make($member, $token);

        foreach (['fromDate', 'toDate'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $parameters[$field] = (string) $filters[$field];
            }
        }

        $parameters['pageNo'] = (int) ($filters['pageNo'] ?? self::DEFAULT_PAGE_NO);
        $parameters['pageSize'] = (int) ($filters['pageSize'] ?? self::DEFAULT_PAGE_SIZE);

        foreach (['businessType', 'assetType', 'assessmentPeriodType'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $parameters[$field] = (string) $filters[$field];
            }
        }

        return $parameters;
    }

    /**
     * 判断第三方资产响应是否业务成功
     *
     * @param  Response  $response  第三方原始响应
     * @return bool HTTP 成功且业务码为 0000 时返回 true
     */
    private function isSuccessful(Response $response): bool
    {
        $code = $response->json('code');

        return $response->successful()
            && is_scalar($code)
            && trim((string) $code) === self::UPSTREAM_SUCCESS_CODE;
    }

    /**
     * 校验并提取第三方资产数据
     *
     * @param  Response  $response  第三方原始响应
     * @return array<string, mixed> 第三方业务数据
     *
     * @throws \Exception 第三方业务失败或响应结构不符合约定时抛出
     */
    private function validatedResponseData(Response $response): array
    {
        $body = $response->json();
        $message = is_array($body) && is_scalar($body['message'] ?? null)
            ? trim((string) $body['message'])
            : '';

        ResponseHelper::errorIf(
            ! $this->isSuccessful($response),
            $message !== '' ? $message : '资产服务查询失败',
            $this->failureApiCode(is_array($body) ? ($body['code'] ?? null) : null),
        );

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        ResponseHelper::errorIf(
            ! is_array($data),
            self::LOCAL_RESPONSE_ERROR_PREFIX.'成功响应的 data 必须是对象',
        );

        return $data;
    }

    /**
     * 标准化第三方资产流水响应
     *
     * @param  array<string, mixed>  $data  第三方业务数据
     * @return Collection<string, mixed> 标准化资产余额、流水和分页数据
     *
     * @throws \Exception 响应缺少列表或分页字段时抛出
     */
    private function normalizeRecordData(array $data): Collection
    {
        $hasLists = is_array($data['balances'] ?? null) && is_array($data['items'] ?? null);
        // 上游空结果可能返回 total=null；items 为空时其语义等同于总数 0。
        $total = $data['total'] ?? null;
        $hasPagination = (($total === null && $data['items'] === []) || is_numeric($total))
            && is_numeric($data['pageNo'] ?? null)
            && is_numeric($data['pageSize'] ?? null);
        ResponseHelper::errorIf(
            ! $hasLists,
            self::LOCAL_RESPONSE_ERROR_PREFIX.'流水响应必须包含 balances 和 items 数组',
        );
        ResponseHelper::errorIf(
            ! $hasPagination,
            self::LOCAL_RESPONSE_ERROR_PREFIX.'流水响应的 total、pageNo、pageSize 必须是数字',
        );

        return collect([
            'balances' => $this->normalizeBalances($data['balances']),
            'items' => $this->normalizeRecordItems($data['items']),
            'total' => (int) ($data['total'] ?? 0),
            'pageNo' => (int) $data['pageNo'],
            'pageSize' => (int) $data['pageSize'],
        ]);
    }

    /**
     * 标准化资产余额列表
     *
     * @param  array<int, mixed>  $balances  第三方资产余额列表
     * @return array<int, array{assetType: string, balance: string|null}> 标准化资产余额列表
     *
     * @throws \Exception 余额记录格式异常时抛出
     */
    private function normalizeBalances(array $balances): array
    {
        $normalizedBalances = [];

        foreach ($balances as $balance) {
            ResponseHelper::errorIf(
                ! is_array($balance),
                self::LOCAL_RESPONSE_ERROR_PREFIX.'balances 中的记录必须是对象',
            );
            $normalizedBalances[] = [
                'assetType' => $this->requiredString($balance, 'assetType'),
                'balance' => $this->nullableString($balance, 'balance'),
            ];
        }

        return $normalizedBalances;
    }

    /**
     * 标准化资产流水列表
     *
     * @param  array<int, mixed>  $items  第三方资产流水列表
     * @return array<int, array<string, string|null>> 标准化资产流水列表
     *
     * @throws \Exception 流水记录格式异常时抛出
     */
    private function normalizeRecordItems(array $items): array
    {
        $normalizedItems = [];

        foreach ($items as $item) {
            ResponseHelper::errorIf(
                ! is_array($item),
                self::LOCAL_RESPONSE_ERROR_PREFIX.'items 中的记录必须是对象',
            );
            $normalizedItems[] = [
                'flowId' => $this->requiredString($item, 'flowId'),
                'businessType' => $this->requiredString($item, 'businessType'),
                'assetType' => $this->requiredString($item, 'assetType'),
                'direction' => $this->requiredString($item, 'direction'),
                'changeQuantity' => $this->requiredString($item, 'changeQuantity'),
                'balanceAfter' => $this->nullableString($item, 'balanceAfter'),
                // 上游部分流水只有发生日期，发生时间为空时保留 null。
                'occurredAt' => $this->nullableString($item, 'occurredAt'),
                'occurredDate' => $this->requiredString($item, 'occurredDate'),
                'assessmentPeriodType' => $this->nullableString($item, 'assessmentPeriodType'),
                'periodStart' => $this->nullableString($item, 'periodStart'),
                'periodEnd' => $this->nullableString($item, 'periodEnd'),
                'description' => $this->nullableString($item, 'description'),
            ];
        }

        return $normalizedItems;
    }

    /**
     * 读取必填字符串字段
     *
     * @param  array<string, mixed>  $data  第三方记录
     * @param  string  $field  字段名
     * @return string 标准化字符串
     *
     * @throws \Exception 字段缺失或不是标量时抛出
     */
    private function requiredString(array $data, string $field): string
    {
        ResponseHelper::errorIf(
            ! is_scalar($data[$field] ?? null),
            self::LOCAL_RESPONSE_ERROR_PREFIX.$field.' 必须是标量',
        );

        return (string) $data[$field];
    }

    /**
     * 读取可空字符串字段
     *
     * @param  array<string, mixed>  $data  第三方记录
     * @param  string  $field  字段名
     * @return string|null 标准化字符串或 null
     *
     * @throws \Exception 非空字段不是标量时抛出
     */
    private function nullableString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;
        ResponseHelper::errorIf(
            $value !== null && ! is_scalar($value),
            self::LOCAL_RESPONSE_ERROR_PREFIX.$field.' 必须是标量或 null',
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * 标准化第三方资产列表
     *
     * 业务逻辑：校验每条资产记录，并过滤上游标记为不可见的资产。
     *
     * @param  array<int, mixed>  $assets  第三方资产列表
     * @return array<int, array{assetType: string, obtainedQuantity: string|null, visible: bool|null}> 标准化资产列表
     *
     * @throws \Exception 任一资产项缺少约定字段或字段类型错误时抛出
     */
    private function normalizeAssets(array $assets): array
    {
        $normalizedAssets = [];

        foreach ($assets as $asset) {
            $isValid = is_array($asset)
                && is_scalar($asset['assetType'] ?? null)
                && array_key_exists('obtainedQuantity', $asset)
                && ($asset['obtainedQuantity'] === null || is_scalar($asset['obtainedQuantity']))
                && array_key_exists('visible', $asset)
                && ($asset['visible'] === null || is_bool($asset['visible']));
            ResponseHelper::errorIf(
                ! $isValid,
                self::LOCAL_RESPONSE_ERROR_PREFIX.'assets 中的记录字段不完整或类型错误',
            );

            if ($asset['visible'] === false) {
                continue;
            }

            $normalizedAssets[] = [
                'assetType' => (string) $asset['assetType'],
                'obtainedQuantity' => $asset['obtainedQuantity'] === null
                    ? null
                    : (string) $asset['obtainedQuantity'],
                'visible' => $asset['visible'],
            ];
        }

        return $normalizedAssets;
    }

    /**
     * 把第三方失败码转换为本项目业务码
     *
     * @param  mixed  $code  第三方业务状态码
     * @return ApiCode 本项目统一业务码
     */
    private function failureApiCode(mixed $code): ApiCode
    {
        if (! is_scalar($code)) {
            return ApiCode::ERROR;
        }

        return match (trim((string) $code)) {
            '0004' => ApiCode::INVALID_PARAMS,
            default => ApiCode::ERROR,
        };
    }
}

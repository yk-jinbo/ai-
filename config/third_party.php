<?php

/**
 * 第三方接口地址集中配置。
 *
 * 业务代码只读取 services.php 暴露的服务配置；更换第三方网关或接口路径时，
 * 只需要维护本文件，不要在 Controller 或 Service 中拼接 URL。
 */
$memberAuthBaseUrl = rtrim((string) env('MEMBER_AUTH_BASE_URL'), '/');
$assetBaseUrl = rtrim((string) env('ASSET_API_BASE_URL'), '/');
$assetBaseUrl = $assetBaseUrl !== '' ? $assetBaseUrl : $memberAuthBaseUrl;
$assetSummaryUrl = trim((string) env('ASSET_SUMMARY_URL'));
$assetRecordsUrl = trim((string) env('ASSET_RECORDS_URL'));
$businessTypesUrl = trim((string) env('BUSINESS_TYPES_URL'));

return [
    'member_auth' => [
        'base_url' => $memberAuthBaseUrl,
    ],

    'assets' => [
        'summary' => $assetSummaryUrl !== ''
            ? $assetSummaryUrl
            : $assetBaseUrl.'/api/v1/computing/query/assets/summary',
        'records' => $assetRecordsUrl !== ''
            ? $assetRecordsUrl
            : $assetBaseUrl.'/api/v1/computing/query/assets/records',
    ],

    'assessment' => [
        'business_types' => $businessTypesUrl !== ''
            ? $businessTypesUrl
            : $assetBaseUrl.'/api/v1/computing/query/business-types',
    ],
];

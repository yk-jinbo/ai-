<?php

/**
 * 第三方接口地址集中配置。
 *
 * 业务代码只读取 services.php 暴露的服务配置；更换第三方网关或接口路径时，
 * 只需要维护本文件，不要在 Controller 或 Service 中拼接 URL。
 */
$memberAuthBaseUrl = rtrim((string) env('MEMBER_AUTH_BASE_URL'), '/');
$assetSummaryUrl = trim((string) env('ASSET_SUMMARY_URL'));
$assetRecordsUrl = trim((string) env('ASSET_RECORDS_URL'));
$assetNetInflowRecordsUrl = trim((string) env('ASSET_NET_INFLOW_RECORDS_URL'));
$businessTypesUrl = trim((string) env('BUSINESS_TYPES_URL'));
$memberIdentitiesUrl = trim((string) env('MEMBER_IDENTITIES_URL'));
$assessmentInfoBaseUrl = $memberAuthBaseUrl;
$assessmentInfoUrls = [
    //个人易货
    'MEMBER_TRADE' => $assessmentInfoBaseUrl.'/api/v1/computing/query/trades/personal/summary',
    //直推易货
    'DIRECT_TRADE' => $assessmentInfoBaseUrl.'/api/v1/computing/query/trades/direct/summary',
    //会员考核
    'MEMBER_ASSESS' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/member',
    //会员激励考核
    'MEMBER_INCENTIVE' => $assessmentInfoBaseUrl.'/api/v1/computing/query/trades/direct/summary',
    //分公司考核
    'BRANCH_COMPANY' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/branch-company',
    //运营中心考核
    'OPERATION_CENTER' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/operation-center',
    //合伙人考核
    'PARTNER' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/partner',
    //招商公司考核
    'MERCHANT_COMPANY' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/merchant',
    //培育
    'CULTIVATION' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/cultivation',
    //代理
    'AGENT' => $assessmentInfoBaseUrl.'/api/v1/computing/query/agents/exchanges',
    //运营中心孵化
    'INCUBATION' => $assessmentInfoBaseUrl.'/api/v1/computing/query/assessments/incubation',
];

return [
    'member_auth' => [
        'base_url' => $memberAuthBaseUrl,
    ],

    'member' => [
        'identities' => $memberIdentitiesUrl !== ''
            ? $memberIdentitiesUrl
            : $memberAuthBaseUrl.'/api/v1/computing/query/members/identities',
    ],

    'assets' => [
        'summary' => $assetSummaryUrl !== ''
            ? $assetSummaryUrl
            : $memberAuthBaseUrl.'/api/v1/computing/query/assets/summary',
        'records' => $assetRecordsUrl !== ''
            ? $assetRecordsUrl
            : $memberAuthBaseUrl.'/api/v1/computing/query/assets/records',
        'net_inflow_records' => $assetNetInflowRecordsUrl !== ''
            ? $assetNetInflowRecordsUrl
            : $memberAuthBaseUrl.'/api/v1/computing/query/organizations/net-deposits',
    ],

    'assessment' => [
        'business_types' => $businessTypesUrl !== ''
            ? $businessTypesUrl
            : $memberAuthBaseUrl.'/api/v1/computing/query/business-types',
        'info' => $assessmentInfoUrls,
    ],
];

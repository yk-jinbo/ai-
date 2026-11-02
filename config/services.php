<?php

// 旧变量保留给原业务网关；登录和数证独立配置，避免切换时影响其他接口。
$legacyGateway = env('MEMBER_AUTH_BASE_URL');
$identityGateway = env('IDENTITY_GATEWAY_BASE_URL', 'http://101.200.147.55:18080');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'external_http' => [
        'gateways' => [
            'default' => env('EXTERNAL_HTTP_BASE_URL', $legacyGateway),
            'identity' => $identityGateway,
        ],
        'connect_timeout' => (int) env('EXTERNAL_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout' => (int) env('EXTERNAL_HTTP_TIMEOUT_SECONDS', 10),
    ],

    'member_auth' => [
        // 保留既有配置键，认证客户端和旧调用方均读取新身份网关。
        'base_url' => $identityGateway,
        'gateway_prefix' => env('MEMBER_AUTH_GATEWAY_PREFIX', 'api-iam'),
        'api_prefix' => env('MEMBER_AUTH_API_PREFIX', 'api/v1'),
        'connect_timeout' => (int) env('MEMBER_AUTH_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout' => (int) env('MEMBER_AUTH_TIMEOUT_SECONDS', 10),
        'token_ttl_seconds' => (int) env('MEMBER_AUTH_TOKEN_TTL_SECONDS', 172800),
    ],

];

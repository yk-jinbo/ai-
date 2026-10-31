<?php

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

    'member_auth' => [
        'base_url' => env('MEMBER_AUTH_BASE_URL'),
        'gateway_prefix' => env('MEMBER_AUTH_GATEWAY_PREFIX', 'api-iam'),
        'api_prefix' => env('MEMBER_AUTH_API_PREFIX', 'api/v1'),
        'connect_timeout' => (int) env('MEMBER_AUTH_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout' => (int) env('MEMBER_AUTH_TIMEOUT_SECONDS', 10),
        'token_ttl_seconds' => (int) env('MEMBER_AUTH_TOKEN_TTL_SECONDS', 172800),
    ],

];

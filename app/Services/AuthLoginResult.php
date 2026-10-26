<?php

namespace App\Services;

/**
 * 本地登录接口返回数据和提示信息。
 */
final readonly class AuthLoginResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $message,
    ) {}
}

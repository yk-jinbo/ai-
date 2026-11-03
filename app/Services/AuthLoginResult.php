<?php

namespace App\Services;

/**
 * 认证流程（含协议查询）向本地接口返回的数据和提示信息。
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

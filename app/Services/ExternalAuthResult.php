<?php

namespace App\Services;

/**
 * 外部认证接口的标准化结果。
 */
final readonly class ExternalAuthResult
{
    public function __construct(
        public bool $success,
        public ?string $bizKey = null,
        public ?string $message = null,
    ) {}
}

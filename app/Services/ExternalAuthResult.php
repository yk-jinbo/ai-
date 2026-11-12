<?php

namespace App\Services;

/**
 * 外部会员认证接口的标准化结果。
 *
 * 负责保留上游业务码、提示、追踪标识和业务数据，供业务服务转换为本项目契约。
 */
final readonly class ExternalAuthResult
{
    /**
     * @param  array<string, mixed>  $data  上游 data 对象
     */
    public function __construct(
        public bool $success,
        public ?string $code = null,
        public ?string $message = null,
        public array $data = [],
        public ?string $traceId = null,
    ) {}
}

<?php

namespace App\Services;

/**
 * 数证中转响应。
 *
 * 保留上游 data 和未识别顶层字段，同时把上游字符串业务码转换为本项目的整数码。
 */
final readonly class CertificateProxyResult
{
    /**
     * @param  mixed  $data  上游 data 字段
     * @param  array<string, mixed>  $extra  上游未识别的顶层字段
     */
    public function __construct(
        public mixed $data,
        public string $message,
        public int $code,
        public int $httpStatus,
        public ?string $traceId = null,
        public array $extra = [],
    ) {}

    /**
     * 返回需要附加到本项目响应顶层的诊断字段。
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return array_merge(
            $this->extra,
            ['traceId' => $this->traceId],
        );
    }
}

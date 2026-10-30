<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * 自动部署测试服务。
 * 生成固定版本标记和可选测试消息，无需读取业务数据即可验证新代码是否生效。
 */
class TestService
{
    private const VERSION = 'test-api-v1';

    /**
     * 生成测试结果，让访问者通过版本标记确认部署是否完成。
     *
     * @param  string|null  $message  已校验的可选测试消息
     * @return Collection<string, string> 测试状态、版本及消息
     */
    public function getStatus(?string $message = null): Collection
    {
        return new Collection([
            'status' => 'ok',
            'version' => self::VERSION,
            'message' => $message ?? '自动部署测试成功',
        ]);
    }
}

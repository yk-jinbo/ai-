<?php

declare(strict_types=1);

namespace App\Caches;

use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;

/**
 * 单个业务 Redis 作用域的最小适配层。
 *
 * 这里只生成 key 并提供 Redis connection；具体命令由业务直接调用。
 *
 * @internal 业务代码应通过 CacheManager 创建作用域。
 */
final class CacheScope
{
    private const HASH_ALGORITHM = 'sha256';

    private const MAX_IDENTIFIER_LENGTH = 256;

    private readonly string $namespace;

    private readonly string $version;

    private readonly string $scope;

    private readonly string $hashKey;

    public function __construct(
        private readonly Connection $redis,
        string $namespace,
        string $version,
        string $scope,
        string $hashKey,
    ) {
        $this->namespace = self::normalizeSegment($namespace, '缓存命名空间');
        $this->version = self::normalizeSegment($version, '缓存版本');
        $this->scope = self::normalizeSegment($scope, '缓存作用域');

        $hashKey = trim($hashKey);
        if ($hashKey === '') {
            throw new InvalidArgumentException('缓存哈希密钥不能为空');
        }

        $this->hashKey = $hashKey;
    }

    /**
     * 获取 Redis connection，直接使用 Redis 原生命令。
     */
    public function connection(): Connection
    {
        return $this->redis;
    }

    /**
     * 生成当前作用域的 Redis key。
     *
     * @param  string|int  $identifier  业务标识
     */
    public function key(string|int $identifier): string
    {
        $identifier = (string) $identifier;

        if (
            trim($identifier) === ''
            || strlen($identifier) > self::MAX_IDENTIFIER_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1
        ) {
            throw new InvalidArgumentException('缓存标识不能为空、不能含控制字符且长度不能超过 '.self::MAX_IDENTIFIER_LENGTH.' 字节');
        }

        $digest = hash_hmac(self::HASH_ALGORITHM, $identifier, $this->hashKey);

        return "{$this->namespace}:{$this->version}:{$this->scope}:{$digest}";
    }

    private static function normalizeSegment(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (
            $value === ''
            || strlen($value) > 64
            || preg_match('/\A[a-z][a-z0-9._-]*\z/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("{$label}必须是 1-64 个小写 ASCII 字符（字母开头，可含数字、点、下划线、短横线）");
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Caches;

use Illuminate\Contracts\Redis\Factory;
use InvalidArgumentException;
use UnitEnum;

/**
 * 应用缓存入口。
 *
 * 业务代码只需要选择一个作用域，底层 Redis connection 和标识摘要均由此处统一处理。
 */
final class CacheManager
{
    private const MAX_SEGMENT_LENGTH = 64;

    private readonly string $namespace;

    private readonly string $version;

    private readonly string $hashKey;

    private readonly string $connection;

    public function __construct(
        private readonly Factory $redis,
        string $namespace = 'app',
        string $version = 'v1',
        ?string $hashKey = null,
        string $connection = 'cache',
    ) {
        $this->namespace = self::normalizeSegment($namespace, '缓存命名空间');
        $this->version = self::normalizeSegment($version, '缓存版本');

        $hashKey = trim((string) $hashKey);
        if ($hashKey === '') {
            throw new InvalidArgumentException('缓存哈希密钥不能为空');
        }

        $this->hashKey = $hashKey;
        $this->connection = self::normalizeConnection($connection);
    }

    /**
     * 创建一个业务 Redis 作用域。
     *
     * @param  string|UnitEnum  $scope  固定的业务作用域名称
     * @param  string|UnitEnum|null  $connection  Redis connection 名称
     */
    public function scope(string|UnitEnum $scope, string|UnitEnum|null $connection = null): CacheScope
    {
        $scope = self::normalizeSegment(self::enumValue($scope), '缓存作用域');
        $connection = $connection === null
            ? $this->connection
            : self::normalizeConnection(self::enumValue($connection));

        return new CacheScope(
            $this->redis->connection($connection),
            $this->namespace,
            $this->version,
            $scope,
            $this->hashKey,
        );
    }

    private static function enumValue(string|UnitEnum $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value instanceof \BackedEnum && is_string($value->value)
            ? $value->value
            : $value->name;
    }

    private static function normalizeSegment(string $value, string $label): string
    {
        $value = strtolower(trim($value));

        if (
            $value === ''
            || strlen($value) > self::MAX_SEGMENT_LENGTH
            || preg_match('/\A[a-z][a-z0-9._-]*\z/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("{$label}必须是 1-".self::MAX_SEGMENT_LENGTH.' 个小写 ASCII 字符（字母开头，可含数字、点、下划线、短横线）');
        }

        return $value;
    }

    private static function normalizeConnection(string $value): string
    {
        $value = trim($value);

        if (
            $value === ''
            || strlen($value) > self::MAX_SEGMENT_LENGTH
            || preg_match('/\A[a-z][a-z0-9._-]*\z/iD', $value) !== 1
        ) {
            throw new InvalidArgumentException('Redis connection 名称不能为空，只能包含 ASCII 字母、数字、点、下划线和短横线');
        }

        return $value;
    }
}

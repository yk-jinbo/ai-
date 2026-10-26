<?php

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case BANNED = 'banned';

    /**
     * 获取中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => '激活',
            self::DISABLED => '禁用',
            self::BANNED => '已封禁',
        };
    }

    /**
     * 是否激活
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}

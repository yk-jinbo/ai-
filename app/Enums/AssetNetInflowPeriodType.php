<?php

namespace App\Enums;

/**
 * 资产净流入周期类型枚举
 * 限定净流入记录支持的自然周期
 */
enum AssetNetInflowPeriodType: string
{
    case MONTH = 'MONTH';
    case QUARTER = 'QUARTER';

    /**
     * 获取净流入周期类型的中文标签
     *
     * @return string 当前周期类型的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::MONTH => '自然月',
            self::QUARTER => '自然季度',
        };
    }
}

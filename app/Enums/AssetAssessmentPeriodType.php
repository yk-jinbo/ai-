<?php

namespace App\Enums;

/**
 * 资产考核周期枚举
 * 统一约束资产流水的周考核与月考核筛选值
 */
enum AssetAssessmentPeriodType: string
{
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';

    /**
     * 获取考核周期的中文标签
     *
     * @return string 当前考核周期的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::WEEK => '周考核',
            self::MONTH => '月考核',
        };
    }
}

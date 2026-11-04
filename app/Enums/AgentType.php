<?php

namespace App\Enums;

/**
 * 代理层级枚举
 * 统一约束代理收益查询支持的省、市、区县代理类型
 */
enum AgentType: string
{
    case PROVINCE = 'PROVINCE';
    case CITY = 'CITY';
    case COUNTY = 'COUNTY';

    /**
     * 获取代理层级的中文名称
     *
     * @return string 当前代理层级的中文名称
     */
    public function label(): string
    {
        return match ($this) {
            self::PROVINCE => '省代理',
            self::CITY => '市代理',
            self::COUNTY => '区县代理',
        };
    }
}

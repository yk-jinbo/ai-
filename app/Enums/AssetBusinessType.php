<?php

namespace App\Enums;

/**
 * 资产业务来源枚举
 * 统一约束资产流水可筛选的十一类业务来源
 */
enum AssetBusinessType: string
{
    case MEMBER_TRADE = 'MEMBER_TRADE';
    case DIRECT_TRADE = 'DIRECT_TRADE';
    case MEMBER_ASSESS = 'MEMBER_ASSESS';
    case MEMBER_INCENTIVE = 'MEMBER_INCENTIVE';
    case BRANCH_COMPANY = 'BRANCH_COMPANY';
    case OPERATION_CENTER = 'OPERATION_CENTER';
    case PARTNER = 'PARTNER';
    case MERCHANT_COMPANY = 'MERCHANT_COMPANY';
    case CULTIVATION = 'CULTIVATION';
    case AGENT = 'AGENT';
    case INCUBATION = 'INCUBATION';

    /**
     * 获取业务来源的中文标签
     *
     * @return string 当前业务来源的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::MEMBER_TRADE => '个人易货',
            self::DIRECT_TRADE => '直推易货',
            self::MEMBER_ASSESS => '会员考核',
            self::MEMBER_INCENTIVE => '会员激励考核',
            self::BRANCH_COMPANY => '分公司考核',
            self::OPERATION_CENTER => '运营中心考核',
            self::PARTNER => '合伙人考核',
            self::MERCHANT_COMPANY => '招商公司考核',
            self::CULTIVATION => '培育',
            self::AGENT => '代理',
            self::INCUBATION => '运营中心孵化',
        };
    }
}

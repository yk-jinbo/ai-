<?php

namespace App\Enums;

/**
 * 资产类型枚举
 * 统一约束资产汇总与流水筛选使用的资产类型值
 */
enum AssetType: string
{
    case GCP = 'GCP';
    case EXCHANGE_VOUCHER = 'EXCHANGE_VOUCHER';
    case PICKUP_VOUCHER = 'PICKUP_VOUCHER';
    case VALUE_COUPON = 'VALUE_COUPON';
    case TONGBAO = 'TONGBAO';
    case CONTRIBUTION = 'CONTRIBUTION';

    /**
     * 获取资产类型的中文标签
     *
     * @return string 当前资产类型的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::GCP => 'GCP权益',
            self::EXCHANGE_VOUCHER => '兑换券',
            self::PICKUP_VOUCHER => '提货券',
            self::VALUE_COUPON => '货值',
            self::TONGBAO => '通宝',
            self::CONTRIBUTION => '贡献值',
        };
    }
}

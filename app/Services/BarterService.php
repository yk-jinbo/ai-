<?php

namespace App\Services;

/**
 * 兑换统计 Demo 服务。
 * 负责提供 barter 统计接口固定示例数据，后续可替换为正式业务查询。
 */
class BarterService
{
    /**
     * 获取 barter 统计示例数据。
     *
     * @return array<string, string> 包含今日通用券和昨日兑换金额
     */
    public function getBarterStatistics(): array
    {
        return [
            'today_general_coupon' => '121.11',
            'yesterday_barter_amount' => '999999.999',
        ];
    }
}

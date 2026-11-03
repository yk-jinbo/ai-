<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * 兑换统计 Demo 服务。
 * 负责提供 barter 统计接口固定示例数据，后续可替换为正式业务查询。
 */
class BarterService
{
    private const HOME_INDICATORS_ENDPOINT = '/api/v1/computing/query/home/indicators';

    public function __construct(
        private ExternalHttpClient $http,
    ) {}

    /**
     * 获取 barter 统计示例数据。
     *
     * @return array<string, string> 包含今日通用券和昨日兑换金额
     */
    public function getBarterStatistics(): array
    {
        $response = $this->http->post(
            url: self::HOME_INDICATORS_ENDPOINT,
            data: [
                'tenantId' => 'demo',
                'mobile' => '13800000002',
                'memberType' => 'NORMAL',
            ],
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'home_indicators',
        );

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('首页指标接口未返回有效数据');
        }

//        return $data;
        return [
            'today_general_coupon' => $data['tongbaoPrice'] ?? '0.00',
            'yesterday_barter_amount' => $data['yesterdayTradeAmount'] ?? '0.00',
        ];
    }
}

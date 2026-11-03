<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
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
     * @param  array<string, mixed>  $user  当前登录用户信息
     * @return array<string, string> 包含今日通用券和昨日兑换金额
     */
    public function getBarterStatistics(array $user, string $token): array
    {
        $mobile = trim((string) ($user['mobile'] ?? ''));
        $memberType = trim((string) ($user['memberType'] ?? $user['member_type'] ?? ''));

        ResponseHelper::errorIf($mobile === '' || $memberType === '' || trim($token) === '', '登录用户信息不完整');

        $response = $this->http->post(
            url: self::HOME_INDICATORS_ENDPOINT,
            data: [
//                'tenantId' => 'demo',
                'token' => trim($token),
                'mobile' => $mobile,
                'memberType' => $memberType,
            ],
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'home_indicators',
        );

        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? $body['result'] ?? null) : null;
        if (! is_array($data) && is_array($body)
            && (array_key_exists('tongbaoPrice', $body) || array_key_exists('yesterdayTradeAmount', $body))) {
            $data = $body;
        }

        if (! is_array($data)) {
            $keys = is_array($body) ? implode(',', array_keys($body)) : get_debug_type($body);
            throw new RuntimeException('首页指标接口响应结构无法识别，响应字段：'.$keys);
        }

        return [
            'today_general_coupon' => $data['tongbaoPrice'] ?? '0.00',
            'yesterday_barter_amount' => $data['yesterdayTradeAmount'] ?? '0.00',
        ];
    }
}

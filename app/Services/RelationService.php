<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * 关系 Demo 业务服务。
 * 负责提供关系模块固定示例数据，后续可替换为真实 Repository 查询。
 */
class RelationService
{
    private const RELATION_SUMMARY_ENDPOINT = 'api/v1/computing/query/relations/summary';
    private const RELATION_RELATIONS_DETAILS = '/api/v1/computing/query/relations/details';

    /**
     * 注入统一外部 HTTP 客户端。
     *
     * @param  ExternalHttpClient  $http  统一外部请求客户端
     * @return void
     */
    public function __construct(private ExternalHttpClient $http) {}

    /**
     * 获取会员当日粉丝统计示例数据。
     *
     * @return array<string, int|string> 粉丝统计数据
     */
    public function getFansStatistics(array $user, string $token): array
    {
        $todayDay = date('Y-m-d', strtotime('-1 day'));
        $identity = $this->identityParameters($user, $token);
        $relations_response = $this->http->post(
            url: self::RELATION_RELATIONS_DETAILS,
            data: array_merge($identity, [
                "asOfDate" => $todayDay,
                "relationType" => "DIRECT",
                "pageNo" => 1,
                "pageSize" => 1
            ]),
            isSuccessful: static fn (Response $relations_response): bool => $relations_response->successful(),
            service: 'relations_details',
        );

        $relations_data = $relations_response->json('data');
        if (! is_array($relations_data)) {
            throw new RuntimeException('关系详情接口未返回有效数据');
        }

        $summary_response = $this->http->post(
            url: self::RELATION_SUMMARY_ENDPOINT,
            data: array_merge($identity, [
                "asOfDate" => $todayDay,
                "relationType" => "DIRECT"
            ]),
            isSuccessful: static fn (Response $summary_response): bool => $summary_response->successful(),
            service: 'relations_summary',
        );

        $summary_data = $summary_response->json('data');
        if (! is_array($summary_data)) {
            throw new RuntimeException('关系汇总接口未返回有效数据');
        }


//        return [
//           'summary' => $summary_data,
//           'relations' => $relations_data,
//        ];
        return [
            'mobile' => $identity['mobile'],
            'parent_mobile' => $summary_data['parentMobile'] ?? '',
            'today_fans_count' => 0,
            'total_fans_count' => $summary_data['memberCount'] ?? '0',
            'my_exchange' => $relations_data['userDailyExchangeAmount'] ?? '0.00',
            'fans_exchange' => $relations_data['relatedDailyExchangeAmount'] ?? '0.00',
            'date_at' => $todayDay,
        ];
    }

    /**
     * 获取粉丝团列表示例数据。
     *
     * 业务逻辑：为每位粉丝补充名称首字，再组装分页结果。
     *
     * @param  int  $page  当前页码
     * @param  int  $pagesize  每页数量
     * @return array<string, mixed> 粉丝团分页数据
     */
    public function getFansList(int $page, int $pagesize, array $user, string $token): array
    {

        $todayDay = date('Y-m-d', strtotime('-1 day'));
        $identity = $this->identityParameters($user, $token);
        $response = $this->http->post(
            url: self::RELATION_RELATIONS_DETAILS,
            data: array_merge($identity, [
                'asOfDate' => $todayDay,
//                'asOfDate' => '2026-10-27',
                'relationType' => 'DIRECT',
                'pageNo' => $page,
                'pageSize' => $pagesize,
            ]),
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'relations_details',
        );

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('关系详情接口未返回有效数据');
        }

//        return $data;
        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            $items[] = [
                'mobile' => $this->maskMobile($item['member']['mobile'] ?? ''),
//                'team_member_id' => 74,
                'fan_name' => $item['member']['memberName'] ?? '',
                'level_title' => $item['member']['memberLevel'] ?? '',
//                'member_avatar' => '111.jpg',
                'today_active' => $item['userDailyExchangeAmount'] ?? '0.00',
                'active_count' => $item['directMemberCount'] ?? '0',
            ];
        }

        // 处理数据
        foreach ($items as $index => $fan) {
            // 按 UTF-8 字符截取，避免中文名称的首字被拆成无效字节。
            $items[$index]['fan_name_abbr'] = mb_substr($fan['fan_name'], 0, 1, 'UTF-8');

        }

        return $this->paginate($items, (int) ($data['total'] ?? 0), $page, $pagesize);
    }

    /**
     * 获取团队会员列表示例数据。
     *
     * @param  int  $page  当前页码
     * @param  int  $pagesize  每页数量
     * @return array<string, mixed> 团队会员分页数据
     */
    public function getTeamList(int $page, int $pagesize, array $user, string $token): array
    {
        $todayDay = date('Y-m-d', strtotime('-1 day'));
        $identity = $this->identityParameters($user, $token);
        $response = $this->http->post(
            url: self::RELATION_RELATIONS_DETAILS,
            data: array_merge($identity, [
                'asOfDate' => $todayDay,
//                'asOfDate' => '2026-10-27',
                'relationType' => 'NETWORK',
                'pageNo' => $page,
                'pageSize' => $pagesize,
            ]),
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'relations_details',
        );

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('关系详情接口未返回有效数据');
        }

//        return $data;
        $items = [];
        foreach ($data['items'] as $item) {
            $items[] = [
                'mobile' => $this->maskMobile($item['member']['mobile'] ?? ''),
//                'team_member_id' => 74,
                'member_name' => $item['member']['memberName'] ?? '',
//                'member_avatar' => '111.jpg',
                'today_exchange_amount' => $item['userDailyExchangeAmount'] ?? '0.00',
                'total_exchange_amount' => $item['memberDailyExchangeAmount'] ?? '0.00',
            ];
        }

        foreach ($items as $index => $fan) {
            // 按 UTF-8 字符截取，避免中文名称的首字被拆成无效字节。
            $items[$index]['member_name_abbr'] = mb_substr($fan['member_name'], 0, 1, 'UTF-8');

        }

        return $this->paginate($items, (int) ($data['total'] ?? 0), $page, $pagesize);
    }

    /**
     * 获取团队统计数据。
     *
     * 业务逻辑：按关系汇总接口契约请求默认业务网关，并返回上游数据。
     *
     * @return array<string, mixed> 上游返回的团队统计数据
     *
     * @throws RuntimeException 上游请求失败或未返回数据时抛出
     */
    public function getTeamStatistics(array $user, string $token): array
    {
        $todayDay = date('Y-m-d', strtotime('-1 day'));
        $identity = $this->identityParameters($user, $token);
        $relations_response = $this->http->post(
            url: self::RELATION_RELATIONS_DETAILS,
            data: array_merge($identity, [
                "asOfDate" => $todayDay,
                "relationType" => "NETWORK",
                "pageNo" => 1,
                "pageSize" => 1
            ]),
            isSuccessful: static fn (Response $relations_response): bool => $relations_response->successful(),
            service: 'relations_details',
        );

        $relations_data = $relations_response->json('data');
        if (! is_array($relations_data)) {
            throw new RuntimeException('关系详情接口未返回有效数据');
        }

        $summary_response = $this->http->post(
            url: self::RELATION_SUMMARY_ENDPOINT,
            data: array_merge($identity, [
                "asOfDate" => $todayDay,
                "relationType" => "NETWORK"
            ]),
            isSuccessful: static fn (Response $summary_response): bool => $summary_response->successful(),
            service: 'relations_summary',
        );

        $summary_data = $summary_response->json('data');
        if (! is_array($summary_data)) {
            throw new RuntimeException('关系汇总接口未返回有效数据');
        }

//        return $data;
        return [
//            'member_id' => 40,
            'mobile' => $identity['mobile'],
            'today_team_member_count' => $relations_data['totalExchangeAmount'] ?? '0.00',
            'today_team_trade_amount' => $relations_data['maxExchangeAmount'] ?? '0.00',
            'today_team_reward_amount' => $relations_data['remainingExchangeAmount'] ?? '0.00',
            'active_team_count' => $summary_data['memberCount'] ?? '0',
            'data_at' => $todayDay,
        ];
    }

    /**
     * 获取团队兑换额汇总示例数据。
     *
     * @return array<string, int|bool> 兑换额统计数据
     */
    public function getExchangeStatistics(array $user, string $token): array
    {
        $todayDay = date('Y-m-d');
//        $todayDay = '2026-10-27';
        $identity = $this->identityParameters($user, $token);

        $response = $this->http->post(
            url: '/api/v1/computing/query/relations/network/flows',
            data: array_merge($identity, [
                'token' => $token,
                'asOfDate' => $todayDay,
                'fromDate' => '2026-10-01',
                'toDate' => $todayDay,
                'pageNo' => 1,
                'pageSize' => 1,
            ]),
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'relations_network_flows',
        );

        $data = $response->json('data');
        //判断有无兑换额详情权限 true有 false无
        if ((string) $response->json('code') === '0020') {
            return [
                'total_exchange_amount' => '0.00',
                'today_exchange_amount' => '0.00',
                'has_exchange_permission' => false,
            ];
        }

        if (! is_array($data)) {
            throw new RuntimeException('关系流水接口未返回有效数据');
        }

        return [
            'total_exchange_amount' => $data['accumulatedExchangeAmount'] ?? '0.00',
            'today_exchange_amount' => $data['dailyExchangeAmount'] ?? '0.00',
            'has_exchange_permission' => true,
        ];
    }

    /**
     * 获取团队兑换额记录示例数据。
     *
     * @return array<string, mixed> 兑换额记录数据
     */
    public function getExchangeStatisticsList(
        string $startDate,
        string $endDate,
        int $page,
        int $pagesize,
        array $user,
        string $token,
    ): array
    {
//        $todayDay = date('Y-m-d');
//        $todayDay = $endDate = '2026-10-27';
        $identity = $this->identityParameters($user, $token);

        $response = $this->http->post(
            url: '/api/v1/computing/query/relations/network/flows',
            data: array_merge($identity, [
                'asOfDate' => $endDate,
                'fromDate' => $startDate,
                'toDate' => $endDate,
                'pageNo' => $page,
                'pageSize' => $pagesize,
            ]),
            isSuccessful: static fn (Response $response): bool => $response->successful(),
            service: 'relations_network_flows',
        );

        $data = $response->json('data');
//        return $data;
        if (! is_array($data)) {
            throw new RuntimeException('关系流水接口未返回有效数据');
        }

        $items = [];
        $items['total_exchange_amount'] = $item['memberDailyExchangeAmount'] ?? '0.00';
        foreach ($data['items'] ?? [] as $item) {
            $mappedItem = [
                'date' => $item['bizDate'] ?? '',
                'exchange_amount' => $item['exchangeAmount'] ?? '0.00',
            ];
            if (array_key_exists('mobile', $item)) {
                $mappedItem['mobile'] = $this->maskMobile($item['mobile']);
            }
            $items['list'][] = $mappedItem;
        }

        usort($items['list'], static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));

        $previousAmount = null;
        foreach ($items['list'] as $index => $item) {
            $exchangeAmount = (float) $item['exchange_amount'];
            $difference = $previousAmount === null ? 0 : abs($exchangeAmount - $previousAmount);
            $items['list'][$index]['difference_from_previous_day'] = number_format($difference, 2, '.', '');
            $items['list'][$index]['difference_previous_day_type'] = $previousAmount === null || $exchangeAmount >= $previousAmount;
            $previousAmount = $exchangeAmount;
        }

        usort($items['list'], static fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        return $this->paginate($items, (int) ($data['total'] ?? 0), $page, $pagesize);
    }

    /**
     * 组装与示例契约一致的分页结构。
     *
     * @param  array<int, array<string, mixed>>  $items  当前页示例项
     * @param  int  $total  总记录数
     * @param  int  $page  当前页码
     * @param  int  $pagesize  每页数量
     * @return array<string, mixed> 分页数据
     */
    private function paginate(array $items, int $total, int $page, int $pagesize): array
    {
        return [
            'current_page' => $page,
            'pagesize' => $pagesize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /** @param array<string, mixed> $user @return array{tenantId: string, token: string, mobile: string, memberType: string} */
    private function identityParameters(array $user, string $token): array
    {
        $mobile = trim((string) ($user['mobile'] ?? ''));
        $memberType = trim((string) ($user['memberType'] ?? $user['member_type'] ?? ''));
        $token = trim($token);

        if ($mobile === '' || $memberType === '' || $token === '') {
            throw new RuntimeException('登录用户信息不完整');
        }

        return [
            'tenantId' => 'demo',
            'token' => $token,
            'mobile' => $mobile,
            'memberType' => $memberType,
        ];
    }

    // 手机号脱敏 只保留后四位
    private function maskMobile(mixed $mobile): string
    {
        $mobile = (string) $mobile;
        if (strlen($mobile) < 7) {
            return $mobile;
        }

        return str_repeat('*', strlen($mobile) - 4).substr($mobile, -4);
    }
}

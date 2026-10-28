<?php

namespace App\Services;

/**
 * 关系 Demo 业务服务。
 * 负责提供关系模块固定示例数据，后续可替换为真实 Repository 查询。
 */
class RelationService
{
    /**
     * 获取会员当日粉丝统计示例数据。
     *
     * @return array<string, int|string> 粉丝统计数据
     */
    public function getFansStatistics(): array
    {
        return [
            'mobile' => '15200000010',
            'parent_mobile' => '',
            'today_fans_count' => 0,
            'total_fans_count' => 11,
            'today_gcp_income' => 0,
            'total_gcp_income' => 1000,
            'new_fans_count' => 0,
            'active_fans_count' => 11,
            'created_at' => '2026-10-28 10:17:00',
        ];
    }

    /**
     * 获取粉丝团列表示例数据。
     *
     * @param  int  $page  当前页码
     * @param  int  $pagesize  每页数量
     * @return array<string, mixed> 粉丝团分页数据
     */
    public function getFansList(int $page, int $pagesize): array
    {
        $items = [
            [
                'mobile' => '*******0010',
                'fan_id' => 59,
                'fan_name' => '直推-10',
                'fan_avatar' => '111.jpg',
                'joined_at' => '2026-09-09 15:00:00',
                'today_active' => 0,
                'level_title' => '秦',
                'active_count' => 5,
            ],
            [
                'mobile' => '*******0009',
                'fan_id' => 58,
                'fan_name' => '直推-9',
                'fan_avatar' => '111.jpg',
                'joined_at' => '2026-09-09 15:00:00',
                'today_active' => 0,
                'level_title' => '秦',
                'active_count' => 0,
            ],
        ];

        return $this->paginate($items, 11, $page, $pagesize);
    }

    /**
     * 获取团队会员列表示例数据。
     *
     * @param  int  $page  当前页码
     * @param  int  $pagesize  每页数量
     * @return array<string, mixed> 团队会员分页数据
     */
    public function getTeamList(int $page, int $pagesize): array
    {
        $items = [
            [
                'mobile' => '*******2194',
                'team_member_id' => 74,
                'member_name' => 'jiyang-74',
                'member_avatar' => '111.jpg',
                'joined_at' => '2026-09-09 16:47:37',
                'today_exchange_amount' => 0,
                'total_exchange_amount' => 0,
            ],
            [
                'mobile' => '*******0020',
                'team_member_id' => 79,
                'member_name' => '直推-20',
                'member_avatar' => '111.jpg',
                'joined_at' => '2026-09-09 15:00:00',
                'today_exchange_amount' => 0,
                'total_exchange_amount' => 0,
            ],
        ];

        return $this->paginate($items, 22, $page, $pagesize);
    }

    /**
     * 获取团队当日统计示例数据。
     *
     * @return array<string, int|string> 团队统计数据
     */
    public function getTeamStatistics(): array
    {
        return [
            'member_id' => 40,
            'mobile' => '15200000010',
            'today_team_member_count' => 40,
            'today_team_trade_amount' => 0,
            'today_team_reward_amount' => 0,
            'active_team_count' => 40,
            'created_at' => '2026-10-28 10:18:57',
        ];
    }

    /**
     * 获取团队兑换额汇总示例数据。
     *
     * @return array<string, int|bool> 兑换额统计数据
     */
    public function getExchangeStatistics(): array
    {
        return [
            'total_exchange_amount' => 13823500,
            'today_exchange_amount' => 0,
            'has_exchange_permission' => true,
        ];
    }

    /**
     * 获取团队兑换额记录示例数据。
     *
     * @return array<string, mixed> 兑换额记录数据
     */
    public function getExchangeStatisticsList(): array
    {
        return [
            'total_exchange_amount' => 3000,
            'list' => [
                [
                    'date' => '2026-10-28',
                    'exchange_amount' => 1000,
                    'difference_from_previous_day' => 0,
                    'difference_previous_day_type' => true,
                ],
                [
                    'date' => '2026-10-29',
                    'exchange_amount' => 2000,
                    'difference_from_previous_day' => 1000,
                    'difference_previous_day_type' => true,
                ],
            ],
        ];
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
}

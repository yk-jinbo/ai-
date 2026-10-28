<?php

namespace App\Http\Controllers\Api\Relation;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 关系统计接口
 *
 * 当前接口返回固定 Demo 数据，待接入正式会员关系统计服务。
 */
#[Prefix('api/relation')]
class RelationController extends Controller
{
    /**
     * 获取会员当日粉丝统计 Demo 数据。
     */
    #[Get('fans-statistics', name: 'api.relation.fans-statistics')]
    public function fansStatistics(): JsonResponse
    {
        return ResponseHelper::success([
            'mobile' => '15200000010',
            'parent_mobile' => '',
            'today_fans_count' => 0,
            'total_fans_count' => 11,
            'today_gcp_income' => 0,
            'total_gcp_income' => 1000,
            'new_fans_count' => 0,
            'active_fans_count' => 11,
            'created_at' => '2026-10-28 10:17:00',
        ], '会员当日统计获取成功');
    }
}

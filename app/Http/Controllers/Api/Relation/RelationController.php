<?php

namespace App\Http\Controllers\Api\Relation;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\RelationRequest;
use App\Services\RelationService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 关系 Demo 接口控制器。
 * 负责接收关系接口请求，并返回会员关系与兑换统计示例数据。
 */
#[Prefix('api/relation')]
class RelationController extends Controller
{
    /**
     * 注入关系 Demo 业务服务。
     */
    public function __construct(
        private RelationService $relationService,
    ) {}

    /**
     * 获取会员当日粉丝统计 Demo 数据。
     */
    #[Get('fans-statistics', name: 'api.relation.fans-statistics')]
    public function fansStatistics(): JsonResponse
    {
        return ResponseHelper::success(
            $this->relationService->getFansStatistics(),
            '会员当日统计获取成功',
        );
    }

    /**
     * 获取粉丝团列表 Demo 数据。
     */
    #[Get('fans-list', name: 'api.relation.fans-list')]
    public function fansList(RelationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ResponseHelper::success(
            $this->relationService->getFansList((int) ($data['page'] ?? 1), (int) ($data['pagesize'] ?? 10)),
            '粉丝团列表获取成功',
        );
    }

    /**
     * 获取团队会员列表 Demo 数据。
     */
    #[Get('team-list', name: 'api.relation.team-list')]
    public function teamList(RelationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ResponseHelper::success(
            $this->relationService->getTeamList((int) ($data['page'] ?? 1), (int) ($data['pagesize'] ?? 10)),
            '团队会员列表获取成功',
        );
    }

    /**
     * 获取团队当日统计 Demo 数据。
     */
    #[Get('team-statistics', name: 'api.relation.team-statistics')]
    public function teamStatistics(): JsonResponse
    {
        return ResponseHelper::success(
            $this->relationService->getTeamStatistics(),
            '团队当日统计获取成功',
        );
    }

    /**
     * 获取团队兑换额汇总 Demo 数据。
     */
    #[Get('exchange-statistics', name: 'api.relation.exchange-statistics')]
    public function exchangeStatistics(): JsonResponse
    {
        return ResponseHelper::success(
            $this->relationService->getExchangeStatistics(),
            '团队兑换额统计获取成功',
        );
    }

    /**
     * 获取团队兑换额记录 Demo 数据。
     */
    #[Get('exchange-statistics-list', name: 'api.relation.exchange-statistics-list')]
    public function exchangeStatisticsList(RelationRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->relationService->getExchangeStatisticsList(),
            '团队兑换额记录获取成功',
        );
    }
}

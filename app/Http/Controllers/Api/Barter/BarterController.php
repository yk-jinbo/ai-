<?php

namespace App\Http\Controllers\Api\Barter;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\BarterService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 兑换统计 Demo 接口控制器。
 * 负责返回 barter 统计示例数据。
 */
#[Prefix('api/barter')]
class BarterController extends Controller
{
    /**
     * 注入兑换统计 Demo 服务。
     *
     * @param  BarterService  $barterService  兑换统计业务服务
     */
    public function __construct(
        private BarterService $barterService,
    ) {}

    /**
     * 获取 barter 统计示例数据。
     *
     * @return JsonResponse 统一结构的 barter 统计响应
     */
    #[Get('barter-statistics', name: 'api.barter.barter-statistics')]
    public function barterStatistics(): JsonResponse
    {
        return ResponseHelper::success(
            $this->barterService->getBarterStatistics(),
            '获取成功',
        );
    }
}

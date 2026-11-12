<?php

namespace App\Http\Controllers\Api\Asset;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMemberToken;
use App\Http\Requests\AssetRequest;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 资产接口控制器
 * 负责接收资产汇总与流水请求，并把请求参数交给资产服务处理
 */
#[Prefix('api')]
class AssetController extends Controller
{
    /**
     * 注入资产业务服务
     *
     * 业务逻辑：保存容器提供的资产服务，供资产汇总与流水接口调用。
     *
     * @param  AssetService  $assetService  资产业务服务
     * @return void
     */
    public function __construct(
        private AssetService $assetService,
    ) {}

    /**
     * 获取会员资产汇总
     *
     * 业务逻辑：从前端令牌读取登录用户，并把用户、原令牌和请求参数交给资产服务。
     *
     * @param  Request  $request  含 Bearer Token 的资产汇总请求
     * @return JsonResponse 统一结构的资产汇总响应
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时向上抛出
     */
    #[Post('/asset/summary', name: 'api.asset.summary', middleware: [AuthenticateMemberToken::class])]
    public function summary(Request $request): JsonResponse
    {
        $user = $request->attributes->get('member_auth_user', []);
        $user = is_array($user) ? $user : [];

        return ResponseHelper::success(
            $this->assetService->summary($user, (string) $request->bearerToken()),
            '资产汇总查询成功',
        );
    }

    /**
     * 获取会员资产流水
     *
     * 业务逻辑：读取 Token 对应的登录用户和已校验筛选条件，交给 Service 查询第三方资产流水。
     *
     * @param  AssetRequest  $request  资产流水请求
     * @return JsonResponse 统一结构的资产流水响应
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时向上抛出
     */
    #[Post('/assets/records', name: 'api.assets.records', middleware: [AuthenticateMemberToken::class])]
    public function records(AssetRequest $request): JsonResponse
    {
        $member = $request->attributes->get('member_auth_user', []);
        $member = is_array($member) ? $member : [];

        return ResponseHelper::success(
            $this->assetService->records(
                $member,
                (string) $request->bearerToken(),
                $request->validated(),
            ),
            '资产流水查询成功',
        );
    }

    /**
     * 获取指定机构的资产净流入记录
     *
     * @param  AssetRequest  $request  已认证并完成参数校验的净流入记录请求
     * @return JsonResponse 统一结构的资产净流入记录响应
     *
     * @throws \Throwable 第三方接口不可用、业务失败或响应格式异常时向上抛出
     */
    #[Post('/asset/net-inflow-records', name: 'api.asset.net-inflow-records', middleware: [AuthenticateMemberToken::class])]
    public function netInflowRecords(AssetRequest $request): JsonResponse
    {
        $member = $request->attributes->get('member_auth_user', []);
        $member = is_array($member) ? $member : [];

        return ResponseHelper::success(
            $this->assetService->netInflowRecords(
                $member,
                (string) $request->bearerToken(),
                $request->validated(),
            ),
            '资产净流入记录查询成功',
        );
    }
}

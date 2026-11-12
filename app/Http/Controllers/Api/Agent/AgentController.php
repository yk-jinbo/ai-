<?php

namespace App\Http\Controllers\Api\Agent;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMemberToken;
use App\Http\Requests\AgentRequest;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 代理接口控制器
 * 负责接收代理收益查询请求并交由服务层处理
 */
#[Prefix('api')]
class AgentController extends Controller
{
    /**
     * 注入代理业务服务
     *
     * @param  AgentService  $agentService  代理业务服务
     * @return void
     */
    public function __construct(
        private AgentService $agentService,
    ) {}

    /**
     * 获取代理收益明细
     *
     * @param  AgentRequest  $request  已认证并通过校验的代理收益查询请求
     * @return JsonResponse 统一结构的代理收益响应
     *
     * @throws \Throwable 第三方接口失败或响应格式异常时抛出
     */
    #[Post('/agent/get-earnings', name: 'api.agent.get-earnings', middleware: [AuthenticateMemberToken::class])]
    public function getEarnings(AgentRequest $request): JsonResponse
    {
        $user = $request->attributes->get('member_auth_user', []);
        $user = is_array($user) ? $user : [];

        return ResponseHelper::success(
            $this->agentService->getEarnings($user, (string) $request->bearerToken(), $request->validated()),
            '获取代理收益明细成功',
        );
    }
}

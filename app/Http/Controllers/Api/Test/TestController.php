<?php

namespace App\Http\Controllers\Api\Test;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\TestRequest;
use App\Services\TestService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 自动部署测试接口控制器。
 * 接收公开测试请求，并返回服务生成的部署验证结果。
 */
#[Prefix('api/test')]
class TestController extends Controller
{
    /**
     * 注入测试服务，统一生成用于验证部署的响应数据。
     *
     * @param  TestService  $testService  测试结果生成服务
     * @return void
     */
    public function __construct(
        private TestService $testService,
    ) {}

    /**
     * 获取测试结果，将校验后的可选消息交给服务处理。
     *
     * @param  TestRequest  $request  测试接口请求
     * @return JsonResponse 统一结构的测试响应
     */
    #[Get('/', name: 'api.test.index')]
    public function index(TestRequest $request): JsonResponse
    {
        $input = $request->validated();

        return ResponseHelper::success(
            $this->testService->getStatus($input['message'] ?? null),
            '测试接口访问成功1',
        );
    }
}

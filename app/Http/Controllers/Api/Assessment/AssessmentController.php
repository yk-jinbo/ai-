<?php

namespace App\Http\Controllers\Api\Assessment;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssessmentRequest;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 考核业务类型接口控制器
 * 负责接收考核业务类型查询并交由服务层处理
 */
#[Prefix('api')]
class AssessmentController extends Controller
{
    /**
     * 注入考核业务服务
     *
     * @param  AssessmentService  $assessmentService  考核业务服务
     * @return void
     */
    public function __construct(
        private AssessmentService $assessmentService,
    ) {}

    /**
     * 获取考核业务类型列表
     *
     * @return JsonResponse 统一结构的业务类型响应
     *
     * @throws \Throwable 第三方接口失败或响应格式异常时抛出
     */
    #[Post('/assessment/get-list', name: 'api.assessment.get-list')]
    public function getList(AssessmentRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->assessmentService->getList(),
            '获取考核业务类型列表成功',
        );
    }

    #[Post('/assessment/assessment-info', name: 'api.assessment.assessment-info')]
    public function assessmentInfo(AssessmentRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->assessmentService->assessmentInfo($request->validated()),
            '查询成功',
        );
    }
}

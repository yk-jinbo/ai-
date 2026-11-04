<?php

namespace App\Http\Controllers\Api\Member;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMemberToken;
use App\Http\Requests\MemberRequest;
use App\Services\MemberService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * 会员接口控制器
 * 负责接收会员身份查询请求并交由服务层处理
 */
#[Prefix('api')]
class MemberController extends Controller
{
    /**
     * 注入会员业务服务
     *
     * @param  MemberService  $memberService 会员业务服务
     * @return void
     */
    public function __construct(
        private MemberService $memberService,
    ) {}

    /**
     * 获取当前会员身份及关联组织
     *
     * @param  MemberRequest  $request 已认证的会员身份查询请求
     * @return JsonResponse 统一结构的会员身份响应
     *
     * @throws \Throwable 第三方接口失败或响应格式异常时抛出
     */
    #[Post('/member/get-identities', name: 'api.member.get-identities', middleware: [AuthenticateMemberToken::class])]
    public function getIdentities(MemberRequest $request): JsonResponse
    {
        $member = $request->attributes->get('member_auth_user', []);
        $member = is_array($member) ? $member : [];

        return ResponseHelper::success(
            $this->memberService->getIdentities($member, (string) $request->bearerToken()),
            '获取会员身份及关联组织成功',
        );
    }
}

<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;

/**
 * 第三方会员身份参数组装器
 * 从当前登录会话提取并校验 Token、手机号和会员类型
 */
final class MemberIdentityParameters
{
    /**
     * 组装第三方接口使用的会员身份参数。
     *
     * @param  array<string, mixed>  $member Token 对应的登录用户信息
     * @param  string  $token 当前请求的 Bearer Token
     * @return array{token: string, mobile: string, memberType: string} 第三方会员身份参数
     */
    public function make(array $member, string $token): array
    {
        $mobile = trim((string) ($member['mobile'] ?? ''));
        $memberType = trim((string) ($member['memberType'] ?? $member['member_type'] ?? ''));

        ResponseHelper::errorIf(trim($token) === '', '未认证或令牌无效', ApiCode::UNAUTHORIZED);
        ResponseHelper::errorIf($mobile === '' || $memberType === '', '登录用户信息不完整');

        return [
            'token' => $token,
            'mobile' => $mobile,
            'memberType' => $memberType,
        ];
    }
}

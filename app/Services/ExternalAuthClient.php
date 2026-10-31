<?php

namespace App\Services;

/**
 * 会员认证上游接口客户端。
 *
 * 业务服务只依赖这些业务动作，不直接依赖 HTTP 请求细节。
 */
interface ExternalAuthClient
{
    /** 发送登录短信验证码。 */
    public function sendSms(string $mobile): ExternalAuthResult;

    /** 校验账号密码并获取可选身份。 */
    public function preparePasswordLogin(string $username, string $password): ExternalAuthResult;

    /** 校验短信验证码并获取可选身份。 */
    public function prepareSmsLogin(string $mobile, string $code): ExternalAuthResult;

    /** 提交身份和协议确认并换取访问令牌。 */
    public function confirmLogin(string $ticket, string $memberType, bool $agreementAccepted): ExternalAuthResult;
}

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

    /**
     * 查询所选身份和场景当前生效的协议清单。
     *
     * @param  string  $memberType  用户选择的身份
     * @param  string  $scene  LOGIN 或 REGISTER 协议场景
     * @return ExternalAuthResult 上游协议版本清单
     */
    public function agreementPolicy(string $memberType, string $scene): ExternalAuthResult;

    /**
     * 读取指定已发布版本的协议正文。
     *
     * @param  string  $versionId  清单返回的版本 ID，不转换为整数
     * @return ExternalAuthResult 上游协议正文
     */
    public function agreementVersion(string $versionId): ExternalAuthResult;

    /**
     * 提交身份和完整协议同意对象以换取访问令牌。
     *
     * @param  string  $ticket  登录校验票据
     * @param  string  $memberType  选择的身份
     * @param  array{accepted: bool, policy_hash?: string, documents?: list<array{document_code: string, version_id: string}>}|null  $agreementConsent  客户端提交的同意信息
     * @return ExternalAuthResult 上游登录确认结果
     */
    public function confirmLogin(string $ticket, string $memberType, ?array $agreementConsent = null): ExternalAuthResult;
}

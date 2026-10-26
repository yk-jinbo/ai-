<?php

namespace App\Services;

use App\Enums\AuthLoginType;

interface ExternalAuthClient
{
    /** 将登录方式和登录信息提交给外部认证接口。 */
    public function login(AuthLoginType $loginType, array $loginInfo): ExternalAuthResult;

    /** 将手机号提交给外部短信接口。 */
    public function sendSms(string $mobile): ExternalAuthResult;
}

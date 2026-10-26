<?php

namespace App\Enums;

enum AuthLoginType: string
{
    case PASSWORD = 'password';
    case SMS = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::PASSWORD => '密码登录',
            self::SMS => '验证码登录',
        };
    }
}

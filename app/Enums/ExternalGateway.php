<?php

namespace App\Enums;

/**
 * 外部网关选择，隔离原业务网关与登录、数证共用的身份网关。
 * 调用方用代码常量选择网关，不根据请求参数或 service 名猜测目标地址。
 */
enum ExternalGateway: string
{
    case DEFAULT = 'default';
    case IDENTITY = 'identity';

    /**
     * 返回网关的中文业务用途，便于阅读日志和开发文档。
     *
     * @return string 当前网关承载的业务范围
     */
    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => '原业务网关',
            self::IDENTITY => '登录与数证网关',
        };
    }
}

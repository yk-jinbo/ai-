<?php

namespace App\Enums;

/**
 * 外部 HTTP 调用失败类型，用于统一检索连接、协议和业务错误。
 */
enum ExternalHttpFailureType: string
{
    case CONFIGURATION = 'configuration';
    case CONNECTION = 'connection';
    case HTTP = 'http';
    case INVALID_RESPONSE = 'invalid_response';
    case BUSINESS = 'upstream_business';
    case EXCEPTION = 'exception';

    /**
     * 返回失败类型对应的中文说明，便于直接阅读日志。
     *
     * @return string 失败原因分类
     */
    public function label(): string
    {
        return match ($this) {
            self::CONFIGURATION => '外部接口配置错误',
            self::CONNECTION => '外部接口连接失败或超时',
            self::HTTP => '外部接口 HTTP 状态异常',
            self::INVALID_RESPONSE => '外部接口响应格式异常',
            self::BUSINESS => '外部接口业务失败',
            self::EXCEPTION => '外部接口调用或响应判断异常',
        };
    }
}

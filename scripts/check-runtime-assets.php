<?php

/**
 * 镜像构建时检查认证运行依赖，避免本地未提交文件造成部署后接口才报错。
 * 不启动应用、不连接数据库或 Redis，也不读取或输出环境凭据。
 */

use App\Enums\ExternalGateway;
use App\Enums\ExternalHttpFailureType;
use App\Services\CaptchaImageGenerator;
use App\Services\ExternalHttpClient;
use App\Services\ExternalHttpLogger;
use App\Services\HttpExternalAuthClient;

require dirname(__DIR__).'/vendor/autoload.php';

$missing = [];
foreach ([ExternalGateway::class, ExternalHttpFailureType::class, ExternalHttpClient::class, ExternalHttpLogger::class, HttpExternalAuthClient::class, CaptchaImageGenerator::class] as $class) {
    if (! class_exists($class)) {
        $missing[] = '缺少运行时类：'.$class;
    }
}

$backgrounds = glob(dirname(__DIR__).'/resources/captcha/backgrounds/*.png');
if (! is_array($backgrounds) || $backgrounds === []) {
    $missing[] = '缺少验证码背景：resources/captcha/backgrounds/*.png';
} else {
    foreach ($backgrounds as $background) {
        if (! is_readable($background)) {
            $missing[] = '验证码背景不可读：'.basename($background);
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, implode(PHP_EOL, $missing).PHP_EOL.'请将所列源码和资源一并提交，再重新构建镜像。'.PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '认证运行时类和验证码背景检查通过。'.PHP_EOL);

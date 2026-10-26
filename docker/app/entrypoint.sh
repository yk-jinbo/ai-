#!/bin/sh
set -eu

# 配置依赖运行时环境变量，避免把密钥和环境差异写入镜像层。
php artisan config:cache --no-ansi

# 属性路由扫描只在启动时执行一次，避免每个 PHP-FPM worker 首次处理请求时重复扫描。
php artisan route:cache --no-ansi

exec "$@"

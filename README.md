# 用户资产中心 PC 管理系统

Laravel 13 / PHP 8.3 纯 API 服务。开发规范与项目知识库见根目录 [AGENTS.md](AGENTS.md)。

## 本地运行

1. 安装 PHP 8.3、Composer 2、MySQL 与 Redis。
2. 复制 `.env.example` 为 `.env`，配置外部主库、`data_bridge` 与 Redis，并填写 `KM_ADMIN_INITIAL_PASSWORD`；外部数据库表由数据库侧维护。
3. 执行 `.scriptscomposer.ps1 install`、`php artisan key:generate` 和 `php artisan serve`。
4. 执行 `php artisan test --no-coverage` 与 `vendor/bin/pint --test`。

业务路由由 `app/Http/Controllers/Api/` 下的属性路由控制器注册，健康检查为 `GET /api/health`。

## 验证码部署检查

`GET /api/auth/captcha` 在本项目生成滑块图片并将目标坐标写入 Redis，不调用外部网关。
部署需包含 `resources/captcha/backgrounds/*.png` 和通用外部请求类、枚举；提交代码时应一并包含新增文件。
镜像构建会执行 `php scripts/check-runtime-assets.php`，发现缺类或缺背景时终止构建。
此检查不连接外部服务；运行时仍需要有效的 `APP_KEY`（或 `CACHE_HASH_KEY`）以及可用的 Redis。

若接口返回“服务异常”，在应用容器查看 `storage/logs/laravel.log` 的对应异常：

```bash
docker compose -f docker-compose.image.yml exec -T app tail -n 80 storage/logs/laravel.log
```

重点区分 `Target class ... does not exist`（源码漏交）、`滑块验证码背景资源不存在`（图片漏交）与 Redis/缓存配置错误。
`external-http` 日志用于外部请求失败，不包含本地验证码生成的异常。

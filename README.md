# 用户资产中心 PC 管理系统

Laravel 13 / PHP 8.3 纯 API 服务。开发规范与项目知识库见根目录 [AGENTS.md](AGENTS.md)。

## 本地运行

1. 安装 PHP 8.3、Composer 2、MySQL 与 Redis。
2. 复制 `.env.example` 为 `.env`，配置外部主库、`data_bridge` 与 Redis，并填写 `KM_ADMIN_INITIAL_PASSWORD`；外部数据库表由数据库侧维护。
3. 执行 `.scriptscomposer.ps1 install`、`php artisan key:generate` 和 `php artisan serve`。
4. 执行 `php artisan test --no-coverage` 与 `vendor/bin/pint --test`。

业务路由由 `app/Http/Controllers/Api/` 下的属性路由控制器注册，健康检查为 `GET /api/health`。
<?php

namespace App\Providers;

use App\Caches\CacheManager;
use App\Models\AdminPersonalAccessTokenModel;
use App\Services\ExternalAuthClient;
use App\Services\HttpExternalAuthClient;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 注册认证上游客户端和缓存管理器绑定。
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(ExternalAuthClient::class, HttpExternalAuthClient::class);

        $this->app->singleton(CacheManager::class, static function (Application $app): CacheManager {
            return new CacheManager(
                $app->make(Factory::class),
                (string) config('cache.namespace'),
                (string) config('cache.version'),
                (string) config('cache.hash_key'),
                (string) config('cache.redis_connection', 'cache'),
            );
        });
    }

    /**
     * 配置 Sanctum 的遗留管理员令牌模型。
     *
     * @return void
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AdminPersonalAccessTokenModel::class);
    }
}

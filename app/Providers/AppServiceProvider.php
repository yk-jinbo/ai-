<?php

namespace App\Providers;

use App\Caches\CacheManager;
use App\Models\AdminPersonalAccessTokenModel;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AdminPersonalAccessTokenModel::class);
    }
}

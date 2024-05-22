<?php

namespace Extensions\SymfonyRedisCache;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ServiceProvider extends LaravelServiceProvider
{
    public function register()
    {
        $this->app->booting(function () {
            Cache::extend('redis', function (Application $app) {
                $config = $app['config']['cache.stores.redis'];
                $prefix = $app['config']['cache.prefix'];

                return Cache::repository(
                    new Store($app['redis'], Str::beforeLast($prefix, ':'), $config['connection']),
                );
            });
        });
    }
}

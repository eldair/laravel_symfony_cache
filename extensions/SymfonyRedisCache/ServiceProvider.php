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

                // Symfony cache adds `:` to the end of namespace which is used as `prefix`
                $prefix = Str::endsWith($prefix, ':') ? substr($prefix, 0, -1) : $prefix;

                return Cache::repository(new Store($app['redis'], $prefix, $config['connection']));
            });
        });
    }
}

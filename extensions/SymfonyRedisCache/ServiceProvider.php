<?php

namespace Extensions\SymfonyRedisCache;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ServiceProvider extends LaravelServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->booting(function (): void {
            Cache::extend('redis', function (Application $app) {
                $config = $app->make(\Illuminate\Contracts\Config\Repository::class)['cache.stores.redis'];
                $prefix = $app->make(\Illuminate\Contracts\Config\Repository::class)['cache.prefix'];
                $serializableClasse = $app->make(\Illuminate\Contracts\Config\Repository::class)[
                    'cache.serializable_classes'
                ];

                // Symfony cache adds `:` to the end of namespace which is used as `prefix`
                $prefix = Str::endsWith($prefix, ':') ? substr($prefix, 0, -1) : $prefix;

                return Cache::repository(
                    new Store(
                        $app->make(\Illuminate\Contracts\Redis\Factory::class),
                        $prefix,
                        $config['connection'],
                        $serializableClasse,
                    ),
                );
            });
        });
    }
}

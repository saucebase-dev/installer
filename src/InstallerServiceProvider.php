<?php

namespace Saucebase\Installer;

use Illuminate\Support\ServiceProvider;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Console\Commands\StackCommand;

class InstallerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                StackCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../stubs/docker/docker-compose.yml' => base_path('docker-compose.yml'),
                __DIR__.'/../stubs/docker/docker/Dockerfile' => base_path('docker/Dockerfile'),
                __DIR__.'/../stubs/docker/docker/nginx.conf' => base_path('docker/nginx.conf'),
                __DIR__.'/../stubs/docker/docker/php.ini' => base_path('docker/php.ini'),
                __DIR__.'/../stubs/docker/docker/xdebug.ini' => base_path('docker/xdebug.ini'),
            ], 'saucebase-docker');
        }
    }
}

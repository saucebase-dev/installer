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
        }
    }
}

<?php

namespace Saucebase\Installer;

use Illuminate\Support\ServiceProvider;
use Saucebase\Installer\Console\Commands\InstallCommand;

class InstallerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}

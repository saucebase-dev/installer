<?php

namespace Saucebase\Installer\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Console\Commands\NewCommand;
use Saucebase\Installer\Console\Commands\StackCommand;

class Application
{
    public static function make(): ConsoleApplication
    {
        $container = new Container;
        Container::setInstance($container);
        $container->singleton(HttpFactory::class);
        Facade::setFacadeApplication($container);

        $console = new ConsoleApplication($container, new Dispatcher($container), static::version());
        $console->setName('Saucebase Installer');

        $console->resolveCommands([
            NewCommand::class,
            InstallCommand::class,
            StackCommand::class,
        ]);

        return $console;
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('saucebase/installer') ?? 'dev';
        } catch (\OutOfBoundsException) {
            return 'dev';
        }
    }
}

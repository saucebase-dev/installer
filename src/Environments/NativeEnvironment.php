<?php

namespace Saucebase\Installer\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;

class NativeEnvironment implements Environment
{
    public function name(): string
    {
        return 'native';
    }

    public function label(): string
    {
        return 'Native PHP';
    }

    public function run(InstallCommand $command): int
    {
        return $command->install();
    }
}

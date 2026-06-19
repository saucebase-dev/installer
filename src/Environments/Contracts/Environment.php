<?php

namespace Saucebase\Installer\Environments\Contracts;

use Saucebase\Installer\Console\Commands\InstallCommand;

interface Environment
{
    public function name(): string;

    public function label(): string;

    public function run(InstallCommand $command): int;
}

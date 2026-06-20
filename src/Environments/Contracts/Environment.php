<?php

namespace Saucebase\Installer\Environments\Contracts;

use Saucebase\Installer\Console\Commands\InstallCommand;

interface Environment
{
    public function name(): string;

    public function label(): string;

    /** @return array<string> Human-readable error messages for each unmet prerequisite; empty means all good. */
    public function missingPrerequisites(): array;

    public function run(InstallCommand $command): int;
}

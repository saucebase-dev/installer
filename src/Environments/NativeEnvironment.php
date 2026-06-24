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

    public function missingPrerequisites(): array
    {
        $missing = [];

        if (! $this->commandExists('composer')) {
            $missing[] = 'composer is not installed or not in PATH.';
        }

        return $missing;
    }

    public function run(InstallCommand $command): int
    {
        return $command->install();
    }

    protected function commandExists(string $name): bool
    {
        return (bool) shell_exec("which {$name} 2>/dev/null");
    }
}

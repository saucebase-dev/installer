<?php

namespace Saucebase\Installer\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;

class NativeEnvironment extends Environment
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

    protected function boot(InstallCommand $command): int
    {
        return $command->install();
    }

    protected function nextSteps(InstallCommand $command): array
    {
        return [
            'Install frontend dependencies: `npm install`',
            'Start the dev server: `composer dev`',
            'Open your app: `'.($this->readEnvValue($command, 'APP_URL') ?? 'http://localhost').'`',
        ];
    }
}

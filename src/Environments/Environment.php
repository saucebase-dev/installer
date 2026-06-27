<?php

namespace Saucebase\Installer\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;

abstract class Environment
{
    abstract public function name(): string;

    abstract public function label(): string;

    /** @return array<string> Human-readable error messages for each unmet prerequisite; empty means all good. */
    abstract public function missingPrerequisites(): array;

    public function run(InstallCommand $command): int
    {
        if (($result = $this->beforePrompts($command)) !== null) {
            return $result;
        }

        $command->promptForModules();

        $result = $this->boot($command);

        if ($result === InstallCommand::SUCCESS) {
            $command->displaySuccess($this->nextSteps($command));
        }

        return $result;
    }

    /** Hook: perform driver-specific steps before the module prompt. Return an exit code to abort, null to continue. */
    protected function beforePrompts(InstallCommand $command): ?int
    {
        return null;
    }

    abstract protected function boot(InstallCommand $command): int;

    /** @return string[] Fully-qualified package names to install (e.g. ['saucebase/auth', 'saucebase/billing']). */
    protected function resolveModules(InstallCommand $command): array
    {
        if ($command->option('all-modules')) {
            $available = $command->fetchAvailableModules();

            return $command->getSelectedStack()
                ? $command->filterModulesByFramework($available, $command->getSelectedStack())
                : $available;
        }

        if ($raw = $command->option('modules')) {
            return array_values(array_filter(array_map(function (string $name): string {
                $name = strtolower(trim($name));

                return $name !== '' ? (str_contains($name, '/') ? $name : "saucebase/{$name}") : '';
            }, explode(',', $raw))));
        }

        return $command->getSelectedModules();
    }

    /** @return string[] */
    abstract protected function nextSteps(InstallCommand $command): array;

    protected function commandExists(string $name): bool
    {
        return (bool) shell_exec("which {$name} 2>/dev/null");
    }
}

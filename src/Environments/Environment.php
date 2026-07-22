<?php

namespace Saucebase\Installer\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;

abstract class Environment
{
    public static function make(string $name): self
    {
        return match ($name) {
            'docker' => new DockerEnvironment,
            'native' => new NativeEnvironment,
            default => throw new \InvalidArgumentException("Unknown driver: {$name}"),
        };
    }

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
            $command->displaySuccess(array_merge($this->cdStep($command), $this->nextSteps($command)));
        }

        return $result;
    }

    /** @return string[] A `cd` step when the target app lives outside the current directory, empty otherwise. */
    protected function cdStep(InstallCommand $command): array
    {
        $target = $this->normalizePath($command->targetPath());
        $cwd = $this->normalizePath(getcwd());

        if ($target === $cwd) {
            return [];
        }

        return ['cd `'.$target.'`'];
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
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
            if ($raw === 'none') {
                return [];
            }

            return array_values(array_filter(array_map(function (string $name): string {
                $name = strtolower(trim($name));

                return $name !== '' ? (str_contains($name, '/') ? $name : "saucebase/{$name}") : '';
            }, explode(',', $raw))));
        }

        return $command->getSelectedModules();
    }

    /** @return string[] */
    abstract protected function nextSteps(InstallCommand $command): array;

    protected function readEnvValue(InstallCommand $command, string $key): ?string
    {
        $env = @file_get_contents($command->path('.env'));
        if ($env === false) {
            return null;
        }
        if (preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $env, $m)) {
            return trim($m[1], "\"'");
        }

        return null;
    }

    public function hasCommand(string $name): bool
    {
        return $this->commandExists($name);
    }

    protected function commandExists(string $name): bool
    {
        return (bool) shell_exec("which {$name} 2>/dev/null");
    }
}

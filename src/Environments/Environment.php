<?php

namespace Saucebase\Installer\Environments;

use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\InstallCommand;

abstract class Environment
{
    /** Label of the step that failed, for the failure callout. Set via fail(). */
    protected ?string $failedStep = null;

    /** Whether buildFrontend() left built assets, so the next steps skip the build. */
    protected bool $frontendBuilt = false;

    public static function make(string $name): Environment
    {
        return match ($name) {
            'docker' => new DockerEnvironment,
            'native' => new NativeEnvironment,
            'herd' => new HerdEnvironment,
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
            $this->frontendBuilt = $this->buildFrontend($command);

            // A module that doesn't fit the installed core installs fine and then breaks
            // every request, so "complete" waits until the app has actually booted.
            if (! $this->artisan($command, ['about', '--only=environment'])) {
                $result = $this->fail('Checking the app boots');
            }
        }

        if ($result === InstallCommand::SUCCESS) {
            $command->displaySuccess(array_merge($this->cdStep($command), $this->nextSteps($command), $this->demoStep($command)));
        } else {
            // Single exit point for every failing step in boot() — the install must
            // never end silently, since the app directory already exists by now.
            $command->displayFailure($this->failedStep, $this->resumeOptions());
        }

        return $result;
    }

    /**
     * Builds the assets so the app's URL works before anyone runs Vite. Node isn't a
     * prerequisite, so without npm, or when the build fails, it stays a next step.
     */
    protected function buildFrontend(InstallCommand $command): bool
    {
        if (! $this->commandExists('npm')) {
            return false;
        }

        $command->info('Building the frontend...');

        if ($command->runProcess(['npm', 'install'], 600) && $command->runProcess(['npm', 'run', 'build'], 600)) {
            return true;
        }

        $command->warn('Building the frontend failed. Run `npm install && npm run build` in the app to see why.');

        return false;
    }

    /** @param  string[]  $args */
    protected function artisan(InstallCommand $command, array $args): bool
    {
        return $command->runArtisan($args);
    }

    /** Record which step failed, then return FAILURE for boot() to propagate. */
    protected function fail(string $step): int
    {
        $this->failedStep = $step;

        return InstallCommand::FAILURE;
    }

    /**
     * Resolved answers a resume run must carry, including ones that came from prompts
     * rather than options.
     *
     * @return array<string, string>
     */
    protected function resumeOptions(): array
    {
        return ['--driver' => $this->name()];
    }

    /** @return string[] A `cd` step when the target app lives outside the current directory, empty otherwise. */
    protected function cdStep(InstallCommand $command): array
    {
        $target = $this->normalizePath($command->targetPath());
        $cwd = $this->normalizePath(getcwd());

        if ($target === $cwd) {
            return [];
        }

        // Relative under the current directory, and quoted only where the shell would split it.
        $path = str_starts_with($target, $cwd.'/') ? substr($target, strlen($cwd) + 1) : $target;

        return ['cd '.(preg_match('#^[\w./-]+$#', $path) === 1 ? $path : escapeshellarg($path))];
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }

    /** @return string[] The demo password, once, when demo content was loaded. */
    protected function demoStep(InstallCommand $command): array
    {
        $password = $command->optionOrNull('demo') ? $this->readEnvValue($command, 'DEMO_PASSWORD') : null;

        return $password === null ? [] : [
            "Sign in to any demo account with the password `{$password}`. It is DEMO_PASSWORD in .env, used when demo data is seeded",
        ];
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
            return $command->fetchAvailableModules();
        }

        if ($raw = $command->option('modules')) {
            if ($raw === 'none') {
                return [];
            }

            return array_values(array_filter(array_map(function (string $name): string {
                $name = strtolower(trim($name));

                if ($name === '' || str_contains($name, '/')) {
                    return $name;
                }

                return "saucebase/{$name}";
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
        return trim(Process::run(['which', $name])->output()) !== '';
    }
}

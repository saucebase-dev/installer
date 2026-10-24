<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Console\Commands\InstallCommand;

class TestableInstallCommand extends InstallCommand
{
    public function setSelectedStack(?string $stack): void
    {
        $this->selectedStack = $stack;
    }

    /** @param  string[]  $modules */
    public function setSelectedModules(array $modules): void
    {
        $this->selectedModules = $modules;
    }

    public function exposedSuccessCalloutContent(array $steps): array
    {
        return $this->successCalloutContent($steps);
    }

    public function exposedFailureCalloutContent(?string $step): array
    {
        return $this->failureCalloutContent($step);
    }

    public function exposedApplyIdentityToEnv(string $env, string $name, string $slug, string $host, bool $native): string
    {
        return $this->applyIdentityToEnv($env, $name, $slug, $host, $native);
    }

    /** @var array<string, bool|string|null> Fake option values for tests that bypass CLI input. */
    public array $fakeOptions = [];

    public function option($key = null): string|array|bool|null
    {
        if (! empty($this->fakeOptions)) {
            return $key !== null ? ($this->fakeOptions[$key] ?? false) : $this->fakeOptions;
        }

        return parent::option($key);
    }

    /** @param  string[]  $available */
    public function exposedResolveModuleSelection(array $available): array
    {
        return $this->resolveModuleSelection($available);
    }

    public function exposedSetupModules(): bool
    {
        return $this->setupModules();
    }

    public function doInstallModules(array $selected, ?\Closure $run = null): bool
    {
        // no-op — prevents composer require from running in unit tests
        return true;
    }
}

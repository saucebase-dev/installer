<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

class TestableInstallCommand extends InstallCommand
{
    /** @var array<string, string[]> Pre-built framework map (overrides HTTP for filtering tests). */
    public array $frameworkFixtures = [];

    private ?string $customModulesBasePath;

    public function setSelectedStack(?string $stack): void
    {
        $this->selectedStack = $stack;
    }

    /** @param  string[]  $modules */
    public function setSelectedModules(array $modules): void
    {
        $this->selectedModules = $modules;
    }

    public function __construct(?string $modulesBasePath = null)
    {
        parent::__construct();
        $this->customModulesBasePath = $modulesBasePath;
    }

    public function exposedFetchPackageFrameworks(string $package): array
    {
        return $this->fetchPackageFrameworks($package);
    }

    /** @param  string[]  $packages */
    public function exposedFilterModulesByFramework(array $packages, string $framework): array
    {
        return $this->filterModulesByFramework($packages, $framework);
    }

    public function exposedSuccessCalloutContent(array $steps): array
    {
        return $this->successCalloutContent($steps);
    }

    /** @param  array<string, string>  $resumeOptions */
    public function exposedFailureCalloutContent(?string $step, array $resumeOptions): array
    {
        return $this->failureCalloutContent($step, $resumeOptions);
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

    public function exposedSetupModules(): void
    {
        $this->setupModules();
    }

    protected function doInstallModules(array $selected): void
    {
        // no-op — prevents composer require from running in unit tests
    }

    protected function fetchPackageFrameworks(string $package): array
    {
        if (isset($this->frameworkFixtures[$package])) {
            return $this->frameworkFixtures[$package];
        }

        return parent::fetchPackageFrameworks($package);
    }

    protected function modulesBasePath(): string
    {
        return $this->customModulesBasePath ?? parent::modulesBasePath();
    }
}

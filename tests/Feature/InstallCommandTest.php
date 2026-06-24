<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // -------------------------------------------------------------------------
    // fetchPackageFrameworks
    // -------------------------------------------------------------------------

    public function test_fetch_package_frameworks_reads_saucebase_extra_field(): void
    {
        Http::fake([
            'raw.githubusercontent.com/saucebase-dev/auth/main/composer.json' => Http::response([
                'extra' => ['saucebase' => ['frameworks' => ['vue', 'react']]],
            ]),
        ]);

        $cmd = new TestableInstallCommand;
        $this->assertSame(['vue', 'react'], $cmd->exposedFetchPackageFrameworks('saucebase/auth'));
    }

    public function test_fetch_package_frameworks_defaults_to_vue_when_field_missing(): void
    {
        Http::fake([
            'raw.githubusercontent.com/saucebase-dev/billing/main/composer.json' => Http::response([
                'extra' => ['laravel' => ['providers' => []]],
            ]),
        ]);

        $cmd = new TestableInstallCommand;
        $this->assertSame(['vue'], $cmd->exposedFetchPackageFrameworks('saucebase/billing'));
    }

    public function test_fetch_package_frameworks_defaults_to_vue_on_api_failure(): void
    {
        Http::fake([
            'raw.githubusercontent.com/saucebase-dev/no-such-module/main/composer.json' => Http::response([], 500),
        ]);

        $cmd = new TestableInstallCommand;
        $this->assertSame(['vue'], $cmd->exposedFetchPackageFrameworks('saucebase/no-such-module'));
    }

    public function test_fetch_package_frameworks_reads_local_composer_json(): void
    {
        // No Http::fake() — Http::preventStrayRequests() will throw if HTTP is called
        $tmpDir = sys_get_temp_dir().'/sb-install-test-'.uniqid();
        mkdir($tmpDir.'/test-fixture', 0755, true);
        file_put_contents($tmpDir.'/test-fixture/composer.json', json_encode([
            'extra' => ['saucebase' => ['frameworks' => ['vue', 'react']]],
        ]));

        try {
            $cmd = new TestableInstallCommand($tmpDir);
            $this->assertSame(['vue', 'react'], $cmd->exposedFetchPackageFrameworks('saucebase/test-fixture'));
        } finally {
            unlink($tmpDir.'/test-fixture/composer.json');
            rmdir($tmpDir.'/test-fixture');
            rmdir($tmpDir);
        }
    }

    public function test_fetch_package_frameworks_falls_back_to_github_when_no_local_file(): void
    {
        Http::fake([
            'raw.githubusercontent.com/saucebase-dev/billing/main/composer.json' => Http::response([
                'extra' => ['saucebase' => ['frameworks' => ['vue', 'react']]],
            ]),
        ]);

        // No modules/billing/composer.json on disk → must fall back to GitHub
        $cmd = new TestableInstallCommand;
        $this->assertSame(['vue', 'react'], $cmd->exposedFetchPackageFrameworks('saucebase/billing'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'saucebase-dev/billing'));
    }

    // -------------------------------------------------------------------------
    // filterModulesByFramework
    // -------------------------------------------------------------------------

    public function test_filter_keeps_all_packages_for_vue(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->frameworkFixtures = [
            'saucebase/auth' => ['vue', 'react'],
            'saucebase/billing' => ['vue'],
            'saucebase/themes' => ['vue'],
        ];

        $result = $cmd->exposedFilterModulesByFramework(
            ['saucebase/auth', 'saucebase/billing', 'saucebase/themes'],
            'vue'
        );

        $this->assertSame(['saucebase/auth', 'saucebase/billing', 'saucebase/themes'], $result);
    }

    public function test_filter_removes_vue_only_packages_for_react(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->frameworkFixtures = [
            'saucebase/auth' => ['vue', 'react'],
            'saucebase/billing' => ['vue'],
            'saucebase/themes' => ['vue'],
        ];

        $result = $cmd->exposedFilterModulesByFramework(
            ['saucebase/auth', 'saucebase/billing', 'saucebase/themes'],
            'react'
        );

        $this->assertSame(['saucebase/auth'], $result);
    }

    public function test_filter_defaults_missing_field_to_vue_only(): void
    {
        $cmd = new TestableInstallCommand;

        Http::fake([
            'raw.githubusercontent.com/saucebase-dev/billing/main/composer.json' => Http::response([
                'extra' => [],
            ]),
        ]);

        $result = $cmd->exposedFilterModulesByFramework(['saucebase/billing'], 'react');

        $this->assertSame([], $result, 'Module without frameworks field should not appear for react');
    }

    // -------------------------------------------------------------------------
    // Deferred stack — saucebase:stack must NOT run during captureStack()
    // -------------------------------------------------------------------------

    public function test_stack_command_is_not_called_during_stack_capture(): void
    {
        $this->fakePackagistList();
        $spy = (object) ['stackCallCount' => 0];

        app()->bind(InstallCommand::class, function () use ($spy) {
            $cmd = new class extends InstallCommand
            {
                public object $spy;

                public function handle(): int
                {
                    $this->captureStack();

                    return self::SUCCESS;
                }

                public function call($command, array $arguments = [], $outputBuffer = null): int
                {
                    if ($command === 'saucebase:stack') {
                        $this->spy->stackCallCount++;
                    }

                    return 0;
                }
            };
            $cmd->spy = $spy;

            return $cmd;
        });

        $this->artisan('saucebase:install vue')->assertSuccessful();

        $this->assertSame(0, $spy->stackCallCount, 'saucebase:stack must not fire during captureStack()');
    }

    public function test_stack_command_is_called_exactly_once_during_install(): void
    {
        $this->fakePackagistList();
        $spy = (object) ['stackCallCount' => 0];

        app()->bind(InstallCommand::class, function () use ($spy) {
            $cmd = new class extends InstallCommand
            {
                public object $spy;

                protected function isCI(): bool
                {
                    return false;
                }

                public function ensureEnvFile(): bool
                {
                    return true;
                }

                protected function generateApplicationKey(): void {}

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): void {}

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                protected function displaySuccess(): void {}

                protected function resolveDriver(): Environment
                {
                    return new NativeEnvironment;
                }

                public function call($command, array $arguments = [], $outputBuffer = null): int
                {
                    if ($command === 'saucebase:stack') {
                        $this->spy->stackCallCount++;
                    }

                    return 0;
                }
            };
            $cmd->spy = $spy;

            return $cmd;
        });

        $this->artisan('saucebase:install vue --all-modules')->assertSuccessful();

        $this->assertSame(1, $spy->stackCallCount, 'saucebase:stack must be called exactly once during install()');
    }

    // -------------------------------------------------------------------------
    // --in-container flag
    // -------------------------------------------------------------------------

    public function test_driver_native_skips_the_select_prompt(): void
    {
        $spy = (object) ['selectCalled' => false];

        app()->bind(InstallCommand::class, function () use ($spy) {
            $cmd = new class extends InstallCommand
            {
                public object $spy;

                protected function isCI(): bool
                {
                    return false;
                }

                protected function resolveDriver(): Environment
                {
                    // if --driver is provided, select() is never reached
                    if ($this->option('driver')) {
                        return new NativeEnvironment;
                    }

                    $this->spy->selectCalled = true;

                    return new NativeEnvironment;
                }

                public function ensureEnvFile(): bool
                {
                    return true;
                }

                protected function generateApplicationKey(): void {}

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): void {}

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                protected function displaySuccess(): void {}
            };
            $cmd->spy = $spy;

            return $cmd;
        });

        $this->artisan('saucebase:install vue --driver=native --all-modules')->assertSuccessful();

        $this->assertFalse($spy->selectCalled, '--driver=native must not reach the select() prompt');
    }

    public function test_driver_native_runs_install_without_prompting(): void
    {

        app()->bind(InstallCommand::class, function () {
            return new class extends InstallCommand
            {
                protected function isCI(): bool
                {
                    return false;
                }

                public function ensureEnvFile(): bool
                {
                    return true;
                }

                protected function generateApplicationKey(): void {}

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): void {}

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                protected function displaySuccess(): void {}
            };
        });

        $this->artisan('saucebase:install vue --driver=native --all-modules')->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites gate in handle()
    // -------------------------------------------------------------------------

    public function test_handle_returns_failure_and_skips_run_when_prerequisites_not_met(): void
    {
        $spy = (object) ['runCalled' => false];

        app()->bind(InstallCommand::class, function () use ($spy) {
            return new class($spy) extends InstallCommand
            {
                public function __construct(private object $spy)
                {
                    parent::__construct();
                }

                protected function isCI(): bool
                {
                    return false;
                }

                protected function resolveDriver(): Environment
                {
                    $spy = $this->spy;

                    return new class($spy) implements Environment
                    {
                        public function __construct(private object $spy) {}

                        public function name(): string { return 'fake'; }

                        public function label(): string { return 'Fake'; }

                        public function missingPrerequisites(): array
                        {
                            return ['fake-tool is not installed or not in PATH.'];
                        }

                        public function run(InstallCommand $command): int
                        {
                            $this->spy->runCalled = true;

                            return Command::SUCCESS;
                        }
                    };
                }
            };
        });

        $this->artisan('saucebase:install vue --all-modules')->assertFailed();
        $this->assertFalse($spy->runCalled, 'run() must not be called when prerequisites are not met');
    }

    // -------------------------------------------------------------------------
    // handleCIInstallation exit codes
    // -------------------------------------------------------------------------

    public function test_ci_installation_returns_failure_when_env_file_missing(): void
    {
        app()->bind(InstallCommand::class, function () {
            return new class extends InstallCommand
            {
                protected function isCI(): bool
                {
                    return true;
                }
            };
        });

        // No .env in the Testbench app root — task will return false
        $this->artisan('saucebase:install vue')->assertFailed();
    }

    // -------------------------------------------------------------------------
    // install() halts on setupDatabase failure
    // -------------------------------------------------------------------------

    public function test_install_returns_failure_and_skips_modules_when_database_setup_fails(): void
    {
        $spy = (object) ['modulesSetupCalled' => false];

        app()->bind(InstallCommand::class, function () use ($spy) {
            $cmd = new class extends InstallCommand
            {
                public object $spy;

                public function ensureEnvFile(): bool
                {
                    return true;
                }

                protected function generateApplicationKey(): void {}

                protected function setupDatabase(): bool
                {
                    return false;
                }

                protected function setupModules(): void
                {
                    $this->spy->modulesSetupCalled = true;
                }

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                protected function displaySuccess(): void {}

                protected function resolveDriver(): Environment
                {
                    return new NativeEnvironment;
                }

                public function call($command, array $arguments = [], $outputBuffer = null): int
                {
                    return 0;
                }
            };
            $cmd->spy = $spy;

            return $cmd;
        });

        $this->artisan('saucebase:install vue --driver=native --all-modules')->assertFailed();
        $this->assertFalse($spy->modulesSetupCalled, 'setupModules() must not run after a failed migration');
    }

    // -------------------------------------------------------------------------
    // resolveModuleSelection — --all-modules framework filtering
    // -------------------------------------------------------------------------

    public function test_all_modules_filters_by_selected_stack(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->setSelectedStack('vue');
        $cmd->fakeOptions = ['all-modules' => true];
        $cmd->frameworkFixtures = [
            'saucebase/auth' => ['vue', 'react'],
            'saucebase/billing' => ['vue'],
            'saucebase/react-only' => ['react'],
        ];

        $result = $cmd->exposedResolveModuleSelection(
            ['saucebase/auth', 'saucebase/billing', 'saucebase/react-only']
        );

        $this->assertContains('saucebase/auth', $result);
        $this->assertContains('saucebase/billing', $result);
        $this->assertNotContains('saucebase/react-only', $result, '--all-modules with vue stack must exclude react-only modules');
    }

    public function test_all_modules_without_stack_returns_all(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->setSelectedStack(null);
        $cmd->fakeOptions = ['all-modules' => true];
        $cmd->frameworkFixtures = [
            'saucebase/auth' => ['vue'],
            'saucebase/react-only' => ['react'],
        ];

        $result = $cmd->exposedResolveModuleSelection(
            ['saucebase/auth', 'saucebase/react-only']
        );

        $this->assertSame(['saucebase/auth', 'saucebase/react-only'], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakePackagistList(): void
    {
        Http::fake([
            'packagist.org/packages/list.json*' => Http::response([
                'packages' => [
                    'saucebase/auth' => ['abandoned' => false],
                ],
            ]),
            'raw.githubusercontent.com/saucebase-dev/auth/main/composer.json' => Http::response([
                'extra' => ['saucebase' => ['frameworks' => ['vue', 'react']]],
            ]),
        ]);
    }
}

/**
 * Exposes protected methods for direct testing.
 *
 * @internal
 */
class TestableInstallCommand extends InstallCommand
{
    /** @var array<string, string[]> Pre-built framework map (overrides HTTP for filtering tests). */
    public array $frameworkFixtures = [];

    private ?string $customModulesBasePath;

    public function setSelectedStack(?string $stack): void
    {
        $this->selectedStack = $stack;
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

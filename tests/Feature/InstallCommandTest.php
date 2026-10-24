<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Elements\NumberedList;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFinishingSteps();
        Http::preventStrayRequests();
    }

    // -------------------------------------------------------------------------
    // Deferred stack — saucebase:stack must NOT run during captureStack()
    // -------------------------------------------------------------------------

    public function test_stack_command_is_not_called_during_stack_capture(): void
    {
        $this->fakePackagistList();
        $spy = (object) ['stackCallCount' => 0];

        $this->bindCommand((function () use ($spy) {
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
                    if ($command === 'stack') {
                        $this->spy->stackCallCount++;
                    }

                    return 0;
                }
            };
            $cmd->spy = $spy;

            return $cmd;
        })());

        $this->artisan('install vue')->assertSuccessful();

        $this->assertSame(0, $spy->stackCallCount, 'saucebase:stack must not fire during captureStack()');
    }

    public function test_only_a_contributor_install_calls_the_stack_command(): void
    {
        $this->fakeToolsInstalled();

        $this->fakePackagistList();
        $spy = (object) ['stackCallCount' => 0];

        $this->bindCommand((function () use ($spy) {
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

                public function installCore(): bool
                {
                    return true;
                }

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): bool
                {
                    return true;
                }

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                public function displaySuccess(array $steps = []): void {}

                protected function resolveDriver(): Environment
                {
                    return new NativeEnvironment;
                }

                public function call($command, array $arguments = [], $outputBuffer = null): int
                {
                    if ($command === 'stack') {
                        $this->spy->stackCallCount++;
                    }

                    return 0;
                }
            };
            $cmd->spy = $spy;

            return $cmd;
        })());

        // An application gets its UI from `php artisan saucebase:install`, not from the stack command.
        $this->artisan('install vue --all-modules')->assertSuccessful();
        $this->assertSame(0, $spy->stackCallCount);

        $this->artisan('install vue --all-modules --dev')->assertSuccessful();
        $this->assertSame(1, $spy->stackCallCount, 'A contributor checkout selects its stack exactly once.');
    }

    // -------------------------------------------------------------------------
    // --in-container flag
    // -------------------------------------------------------------------------

    public function test_driver_native_skips_the_select_prompt(): void
    {
        $this->fakeToolsInstalled();

        $spy = (object) ['selectCalled' => false];

        $this->bindCommand((function () use ($spy) {
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

                public function installCore(): bool
                {
                    return true;
                }

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): bool
                {
                    return true;
                }

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                public function displaySuccess(array $steps = []): void {}
            };
            $cmd->spy = $spy;

            return $cmd;
        })());

        $this->artisan('install vue --driver=native --all-modules')->assertSuccessful();

        $this->assertFalse($spy->selectCalled, '--driver=native must not reach the select() prompt');
    }

    public function test_driver_native_runs_install_without_prompting(): void
    {
        $this->fakeToolsInstalled();

        $this->bindCommand((function () {
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

                public function installCore(): bool
                {
                    return true;
                }

                protected function setupDatabase(): bool
                {
                    return true;
                }

                protected function setupModules(): bool
                {
                    return true;
                }

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                public function displaySuccess(array $steps = []): void {}
            };
        })());

        $this->artisan('install vue --driver=native --all-modules')->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites gate in handle()
    // -------------------------------------------------------------------------

    public function test_handle_returns_failure_and_skips_run_when_prerequisites_not_met(): void
    {
        $spy = (object) ['runCalled' => false];

        $this->bindCommand((function () use ($spy) {
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

                    return new class($spy) extends Environment
                    {
                        public function __construct(private object $spy) {}

                        public function name(): string
                        {
                            return 'fake';
                        }

                        public function label(): string
                        {
                            return 'Fake';
                        }

                        public function missingPrerequisites(): array
                        {
                            return ['fake-tool is not installed or not in PATH.'];
                        }

                        public function run(InstallCommand $command): int
                        {
                            $this->spy->runCalled = true;

                            return Command::SUCCESS;
                        }

                        protected function boot(InstallCommand $command): int
                        {
                            return Command::SUCCESS;
                        }

                        protected function nextSteps(InstallCommand $command): array
                        {
                            return [];
                        }
                    };
                }
            };
        })());

        $this->artisan('install vue --all-modules')->assertFailed();
        $this->assertFalse($spy->runCalled, 'run() must not be called when prerequisites are not met');
    }

    // -------------------------------------------------------------------------
    // handleCIInstallation exit codes
    // -------------------------------------------------------------------------

    public function test_ci_installation_returns_failure_when_env_file_missing(): void
    {
        $this->bindCommand((function () {
            return new class extends InstallCommand
            {
                protected function isCI(): bool
                {
                    return true;
                }

                public function ensureEnvFile(): bool
                {
                    return false;
                }
            };
        })());

        $this->artisan('install vue')->assertFailed();
    }

    // -------------------------------------------------------------------------
    // install() halts on setupDatabase failure
    // -------------------------------------------------------------------------

    public function test_install_returns_failure_and_skips_modules_when_database_setup_fails(): void
    {
        $this->fakeToolsInstalled();

        $spy = (object) ['modulesSetupCalled' => false];

        $this->bindCommand((function () use ($spy) {
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

                public function installCore(): bool
                {
                    return true;
                }

                protected function setupDatabase(): bool
                {
                    return false;
                }

                protected function setupModules(): bool
                {
                    $this->spy->modulesSetupCalled = true;

                    return true;
                }

                protected function createStorageLink(): void {}

                protected function clearCaches(): void {}

                public function displaySuccess(array $steps = []): void {}

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
        })());

        $this->artisan('install vue --driver=native --all-modules')->assertFailed();
        $this->assertFalse($spy->modulesSetupCalled, 'setupModules() must not run after a failed migration');
    }

    // -------------------------------------------------------------------------
    // resolveModuleSelection — --all-modules framework filtering
    // -------------------------------------------------------------------------

    public function test_all_modules_takes_every_module_whatever_the_stack(): void
    {
        // A private module's stacks can't be read before it is installed, so none is refused.
        $cmd = new TestableInstallCommand;
        $cmd->setSelectedStack('react');
        $cmd->fakeOptions = ['all-modules' => true];

        $result = $cmd->exposedResolveModuleSelection(['saucebase/auth', 'saucebase/tenancy']);

        $this->assertSame(['saucebase/auth', 'saucebase/tenancy'], $result);
    }

    public function test_all_modules_without_stack_returns_all(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->setSelectedStack(null);
        $cmd->fakeOptions = ['all-modules' => true];

        $result = $cmd->exposedResolveModuleSelection(
            ['saucebase/auth', 'saucebase/react-only']
        );

        $this->assertSame(['saucebase/auth', 'saucebase/react-only'], $result);
    }

    // -------------------------------------------------------------------------
    // resolveModuleSelection — --modules= matching
    // -------------------------------------------------------------------------

    public function test_resolve_matches_full_package_name(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->fakeOptions = ['modules' => 'saucebase/auth'];

        $result = $cmd->exposedResolveModuleSelection(['saucebase/auth', 'saucebase/billing']);

        $this->assertSame(['saucebase/auth'], $result);
    }

    public function test_resolve_matches_short_name(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->fakeOptions = ['modules' => 'auth'];

        $result = $cmd->exposedResolveModuleSelection(['saucebase/auth', 'saucebase/billing']);

        $this->assertSame(['saucebase/auth'], $result);
    }

    public function test_resolve_matches_mixed_case_short_name(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->fakeOptions = ['modules' => 'Auth'];

        $result = $cmd->exposedResolveModuleSelection(['saucebase/auth', 'saucebase/billing']);

        $this->assertSame(['saucebase/auth'], $result);
    }

    public function test_resolve_matches_multiple_full_package_names(): void
    {
        $cmd = new TestableInstallCommand;
        $cmd->fakeOptions = ['modules' => 'saucebase/auth,saucebase/billing'];

        $result = $cmd->exposedResolveModuleSelection(['saucebase/auth', 'saucebase/billing', 'saucebase/settings']);

        $this->assertSame(['saucebase/auth', 'saucebase/billing'], $result);
    }

    // -------------------------------------------------------------------------
    // displaySuccess — sequential step numbering
    // -------------------------------------------------------------------------

    public function test_display_success_numbers_steps_sequentially(): void
    {
        $cmd = new TestableInstallCommand;

        $content = $cmd->exposedSuccessCalloutContent([5 => 'first step', 10 => 'second step']);

        $list = null;
        foreach ($content as $part) {
            if ($part instanceof NumberedList) {
                $list = $part;
            }
        }

        $this->assertNotNull($list);
        $this->assertSame(['first step', 'second step'], $list->items);
    }

    // -------------------------------------------------------------------------
    // setupModules — Packagist fast-path
    // -------------------------------------------------------------------------

    public function test_setup_modules_skips_packagist_when_fully_qualified_names_given(): void
    {
        $cmd = new class extends TestableInstallCommand
        {
            public bool $fetchCalled = false;

            public function fetchAvailableModules(): array
            {
                $this->fetchCalled = true;

                return [];
            }
        };
        $cmd->fakeOptions = ['modules' => 'saucebase/auth'];
        $cmd->exposedSetupModules();

        $this->assertFalse($cmd->fetchCalled, 'Packagist must not be fetched when all names are fully qualified');
    }

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

<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Environment;
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

                public function displaySuccess(array $steps = []): void {}

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

                public function displaySuccess(array $steps = []): void {}
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

                public function displaySuccess(array $steps = []): void {}
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

                public function ensureEnvFile(): bool
                {
                    return false;
                }
            };
        });

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
                    return false;
                }

                protected function setupModules(): void
                {
                    $this->spy->modulesSetupCalled = true;
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
    // moduleHasSeeder — vendor/ path
    // -------------------------------------------------------------------------

    public function test_module_has_seeder_checks_vendor_path(): void
    {
        $name = 'sb-test-seeder-'.uniqid();
        $vendorSeederDir = base_path("vendor/saucebase/{$name}/database/seeders");
        @mkdir($vendorSeederDir, 0755, true);
        file_put_contents($vendorSeederDir.'/DatabaseSeeder.php', '<?php');

        try {
            $cmd = new TestableInstallCommand;
            $this->assertFalse(
                file_exists(base_path("modules/{$name}/database/seeders/DatabaseSeeder.php")),
                'modules/ path must not exist for this test to be valid'
            );
            $this->assertTrue($cmd->moduleHasSeeder($name), 'moduleHasSeeder() must detect seeder in vendor/saucebase/');
        } finally {
            @unlink($vendorSeederDir.'/DatabaseSeeder.php');
            @rmdir($vendorSeederDir);
            @rmdir(base_path("vendor/saucebase/{$name}/database"));
            @rmdir(base_path("vendor/saucebase/{$name}"));
        }
    }

    // -------------------------------------------------------------------------
    // displaySuccess — sequential step numbering
    // -------------------------------------------------------------------------

    public function test_display_success_numbers_steps_sequentially(): void
    {
        $cmd = new class extends TestableInstallCommand
        {
            public array $capturedLines = [];

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->capturedLines[] = $string;
            }

            public function info($string, $verbosity = null): void {}

            public function newLine($count = 1): static
            {
                return $this;
            }
        };

        $cmd->displaySuccess([5 => 'first step', 10 => 'second step']);

        $stepLines = implode(' ', array_filter(
            $cmd->capturedLines,
            fn ($l) => (bool) preg_match('/\d+\./', $l),
        ));

        $this->assertStringContainsString('1. first step', $stepLines);
        $this->assertStringContainsString('2. second step', $stepLines);
        $this->assertStringNotContainsString('6. first step', $stepLines);
        $this->assertStringNotContainsString('11. second step', $stepLines);
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

    // -------------------------------------------------------------------------
    // rewriteCrossModuleImports
    // -------------------------------------------------------------------------

    public function test_rewrite_cross_module_imports_strips_all_framework_segments(): void
    {
        $jsRoot = base_path('modules/sb-test-rewrite/resources/js');
        @mkdir($jsRoot, 0755, true);
        file_put_contents($jsRoot.'/app.ts', implode("\n", [
            "import Foo from '@modules/other/resources/js/vue/components/Foo.vue';",
            "import Bar from '@modules/other/resources/js/react/Bar.tsx';",
        ]));

        try {
            $cmd = new TestableInstallCommand;
            $cmd->rewriteCrossModuleImports();

            $result = file_get_contents($jsRoot.'/app.ts');
            $this->assertStringContainsString("@modules/other/resources/js/components/Foo.vue", $result);
            $this->assertStringContainsString("@modules/other/resources/js/Bar.tsx", $result);
            $this->assertStringNotContainsString('/vue/', $result);
            $this->assertStringNotContainsString('/react/', $result);
        } finally {
            @unlink($jsRoot.'/app.ts');
            @rmdir($jsRoot);
            @rmdir(base_path('modules/sb-test-rewrite/resources'));
            @rmdir(base_path('modules/sb-test-rewrite'));
        }
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

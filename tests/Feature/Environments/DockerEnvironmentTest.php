<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Tests\TestCase;

class DockerEnvironmentTest extends TestCase
{
    public function test_name_is_docker(): void
    {
        $this->assertSame('docker', (new DockerEnvironment)->name());
    }

    public function test_label_is_set(): void
    {
        $this->assertNotEmpty((new DockerEnvironment)->label());
    }

    public function test_implements_environment_contract(): void
    {
        $this->assertInstanceOf(Environment::class, new DockerEnvironment);
    }

    // -------------------------------------------------------------------------
    // buildContainerArgs
    // -------------------------------------------------------------------------

    public function test_always_includes_driver_native_and_force_flags(): void
    {
        $args = $this->buildArgs();

        $this->assertContains('--driver=native', $args);
        $this->assertContains('--force', $args);
    }

    public function test_always_includes_artisan_command(): void
    {
        $args = $this->buildArgs();

        $this->assertContains('php', $args);
        $this->assertContains('artisan', $args);
        $this->assertContains('saucebase:install', $args);
    }

    public function test_forwards_stack_argument(): void
    {
        $args = $this->buildArgs(stack: 'vue');

        $this->assertContains('vue', $args);
    }

    public function test_forwards_react_stack(): void
    {
        $args = $this->buildArgs(stack: 'react');

        $this->assertContains('react', $args);
    }

    public function test_omits_stack_when_null(): void
    {
        $args = $this->buildArgs(stack: null);

        $this->assertNotContains('vue', $args);
        $this->assertNotContains('react', $args);
    }

    public function test_forwards_selected_modules_as_comma_list(): void
    {
        $args = $this->buildArgs(modules: ['saucebase/auth', 'saucebase/billing']);

        $this->assertContains('--modules=saucebase/auth,saucebase/billing', $args);
    }

    public function test_omits_modules_arg_when_none_selected(): void
    {
        $args = $this->buildArgs(modules: []);

        $matched = array_filter($args, fn (string $a) => str_starts_with($a, '--modules='));
        $this->assertEmpty($matched);
    }

    public function test_forwards_fresh_flag(): void
    {
        $args = $this->buildArgs(options: ['fresh' => true]);

        $this->assertContains('--fresh', $args);
    }

    public function test_omits_fresh_when_not_set(): void
    {
        $args = $this->buildArgs(options: ['fresh' => false]);

        $this->assertNotContains('--fresh', $args);
    }

    public function test_forwards_dev_flag(): void
    {
        $args = $this->buildArgs(options: ['dev' => true]);

        $this->assertContains('--dev', $args);
    }

    public function test_forwards_all_modules_flag(): void
    {
        $args = $this->buildArgs(options: ['all-modules' => true]);

        $this->assertContains('--all-modules', $args);
    }

    public function test_multiple_flags_are_all_forwarded(): void
    {
        $args = $this->buildArgs(
            stack: 'react',
            modules: ['saucebase/auth'],
            options: ['fresh' => true, 'dev' => true],
        );

        $this->assertContains('react', $args);
        $this->assertContains('--modules=saucebase/auth', $args);
        $this->assertContains('--fresh', $args);
        $this->assertContains('--dev', $args);
        $this->assertContains('--driver=native', $args);
        $this->assertContains('--force', $args);
    }

    // -------------------------------------------------------------------------
    // run() failure propagation
    // -------------------------------------------------------------------------

    public function test_run_returns_failure_and_skips_composer_when_docker_fails_to_start(): void
    {
        $spy = (object) ['composerCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function publishStubs(InstallCommand $command): void {}

            protected function generateSsl(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return false;
            }

            protected function runComposerInContainer(InstallCommand $command): bool
            {
                $this->spy->composerCalled = true;

                return true;
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result);
        $this->assertFalse($spy->composerCalled, 'composer must not run when Docker fails to start');
    }

    public function test_run_returns_failure_when_composer_install_fails_in_container(): void
    {
        $spy = (object) ['installCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function publishStubs(InstallCommand $command): void {}

            protected function generateSsl(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return true;
            }

            protected function runComposerInContainer(InstallCommand $command): bool
            {
                return false;
            }

            protected function runInstallInContainer(InstallCommand $command): void
            {
                $this->spy->installCalled = true;
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result);
        $this->assertFalse($spy->installCalled, 'in-container install must not run when composer install fails');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  string[]  $modules
     * @param  array<string, bool>  $options
     * @return string[]
     */
    private function buildArgs(
        ?string $stack = null,
        array $modules = [],
        array $options = [],
    ): array {
        $command = new FakeInstallCommand($stack, $modules, $options);

        return (new DockerEnvironment)->buildContainerArgs($command);
    }
}

/**
 * Minimal InstallCommand stub for testing buildContainerArgs() without
 * needing a real Symfony Console input binding.
 *
 * @internal
 */
class FakeInstallCommand extends InstallCommand
{
    /**
     * @param  string[]  $modules
     * @param  array<string, bool>  $options
     */
    public function __construct(
        private ?string $stack,
        private array $modules,
        private array $fakeOptions,
    ) {}

    public function getSelectedStack(): ?string
    {
        return $this->stack;
    }

    /** @return string[] */
    public function getSelectedModules(): array
    {
        return $this->modules;
    }

    public function option($key = null): string|array|bool|null
    {
        if ($key !== null) {
            return $this->fakeOptions[$key] ?? false;
        }

        return $this->fakeOptions;
    }
}

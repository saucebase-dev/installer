<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

class NativeEnvironmentTest extends TestCase
{
    public function test_name_is_native(): void
    {
        $this->assertSame('native', (new NativeEnvironment)->name());
    }

    public function test_label_is_set(): void
    {
        $this->assertNotEmpty((new NativeEnvironment)->label());
    }

    public function test_extends_environment_base(): void
    {
        $this->assertInstanceOf(Environment::class, new NativeEnvironment);
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites
    // -------------------------------------------------------------------------

    public function test_missing_prerequisites_returns_empty_when_composer_present(): void
    {
        $env = new class extends NativeEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return true;
            }
        };

        $this->assertSame([], $env->missingPrerequisites());
    }

    public function test_missing_prerequisites_reports_composer_missing(): void
    {
        $env = new class extends NativeEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return false;
            }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('composer', $missing[0]);
    }

    // -------------------------------------------------------------------------
    // run()
    // -------------------------------------------------------------------------

    public function test_run_delegates_to_install_and_returns_success(): void
    {
        $spy = (object) ['installCalled' => false];

        $command = new class($spy) extends InstallCommand
            {
                public function __construct(public object $spy) {}

                public function promptForModules(): void {}

                public function displaySuccess(array $steps = []): void {}

                public function install(): int
                {
                    $this->spy->installCalled = true;

                    return Command::SUCCESS;
                }
            };

        $env = new NativeEnvironment;
        $result = $env->run($command);

        $this->assertTrue($spy->installCalled);
        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_run_passes_through_failure_from_install(): void
    {
        $command = new class extends InstallCommand
            {
                public function promptForModules(): void {}

                public function displaySuccess(array $steps = []): void {}

                public function install(): int
                {
                    return Command::FAILURE;
                }
            };

        $env = new NativeEnvironment;

        $this->assertSame(Command::FAILURE, $env->run($command));
    }
}

<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;
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

    public function test_implements_environment_contract(): void
    {
        $this->assertInstanceOf(Environment::class, new NativeEnvironment);
    }

    public function test_run_delegates_to_install_and_returns_success(): void
    {
        $spy = (object) ['installCalled' => false];

        app()->bind(InstallCommand::class, function () use ($spy) {
            return new class($spy) extends InstallCommand
            {
                public function __construct(public object $spy) {}

                public function install(): int
                {
                    $this->spy->installCalled = true;

                    return Command::SUCCESS;
                }
            };
        });

        $env = new NativeEnvironment;
        $command = app(InstallCommand::class);
        $result = $env->run($command);

        $this->assertTrue($spy->installCalled);
        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_run_passes_through_failure_from_install(): void
    {
        app()->bind(InstallCommand::class, function () {
            return new class extends InstallCommand
            {
                public function install(): int
                {
                    return Command::FAILURE;
                }
            };
        });

        $env = new NativeEnvironment;
        $command = app(InstallCommand::class);

        $this->assertSame(Command::FAILURE, $env->run($command));
    }
}

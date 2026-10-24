<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

/** What every driver does once its own install steps succeeded: build the frontend, then check the app boots. */
class FinishingStepsTest extends TestCase
{
    public function test_the_frontend_is_built_so_the_app_works_straight_away(): void
    {
        Process::fake([
            "'which' *" => Process::result('/usr/local/bin/npm'),
            "'npm' 'install'*" => Process::result(),
            "'npm' 'run' 'build'*" => Process::result(),
            "*'about'*" => Process::result(),
        ]);
        $command = $this->command();

        $this->assertSame(Command::SUCCESS, $this->environment()->run($command));

        Process::assertRan(fn ($process) => $process->command === ['npm', 'install']);
        Process::assertRan(fn ($process) => $process->command === ['npm', 'run', 'build']);
        $this->assertNotContains('Install frontend dependencies: `npm install`', $command->steps);
    }

    public function test_without_npm_the_build_is_left_to_the_next_steps(): void
    {
        Process::fake([
            "'which' *" => Process::result(''),
            "*'about'*" => Process::result(),
        ]);
        $command = $this->command();

        $this->assertSame(Command::SUCCESS, $this->environment()->run($command));

        Process::assertDidntRun(fn ($process) => $process->command === ['npm', 'install']);
        $this->assertContains('Install frontend dependencies: `npm install`', $command->steps);
    }

    public function test_a_failed_build_warns_but_keeps_the_install(): void
    {
        Process::fake([
            "'which' *" => Process::result('/usr/local/bin/npm'),
            "'npm' 'install'*" => Process::result(),
            "'npm' 'run' 'build'*" => Process::result(errorOutput: 'boom', exitCode: 1),
            "*'about'*" => Process::result(),
        ]);
        $command = $this->command();

        $this->assertSame(Command::SUCCESS, $this->environment()->run($command));

        $this->assertNotEmpty($command->warnings);
        $this->assertContains('Install frontend dependencies: `npm install`', $command->steps);
    }

    public function test_an_app_that_does_not_boot_is_not_reported_as_installed(): void
    {
        Process::fake([
            "'which' *" => Process::result(''),
            "*'about'*" => Process::result(errorOutput: 'Class "App\Providers\ModuleServiceProvider" not found', exitCode: 1),
        ]);
        $command = $this->command();

        $this->assertSame(Command::FAILURE, $this->environment()->run($command));

        $this->assertNull($command->steps, 'no success callout');
        $this->assertSame('Checking the app boots', $command->failedStep);
    }

    private function environment(): NativeEnvironment
    {
        return new class extends NativeEnvironment
        {
            protected function boot(InstallCommand $command): int
            {
                return InstallCommand::SUCCESS;
            }
        };
    }

    private function command(): InstallCommand
    {
        return new class extends InstallCommand
        {
            /** @var string[]|null */
            public ?array $steps = null;

            public ?string $failedStep = null;

            /** @var string[] */
            public array $warnings = [];

            public function __construct() {}

            public function targetPath(): string
            {
                return sys_get_temp_dir();
            }

            public function promptForModules(): void {}

            public function displaySuccess(array $steps = []): void
            {
                $this->steps = $steps;
            }

            public function displayFailure(?string $step = null, array $resumeOptions = []): void
            {
                $this->failedStep = $step;
            }

            public function warn($string, $verbosity = null): void
            {
                $this->warnings[] = $string;
            }

            public function line($string, $style = null, $verbosity = null): void {}

            public function info($string, $verbosity = null): void {}
        };
    }
}

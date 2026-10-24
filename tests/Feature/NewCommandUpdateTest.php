<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\NewCommand;
use Saucebase\Installer\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** An app is created by the newest installer: an older one updates itself first, then runs again. */
class NewCommandUpdateTest extends TestCase
{
    public function test_an_older_installer_updates_itself_and_runs_the_command_again(): void
    {
        Process::fake([
            "'composer' 'global' 'require'*" => Process::result(),
            "*'new' 'acme'*" => Process::result(exitCode: 0),
        ]);

        $this->assertSame(0, $this->command(current: '2.9.0', latest: '3.5.0')->check());

        // `require` with the new version moves the global constraint, so a major update works too.
        Process::assertRan(fn ($process) => $process->command === ['composer', 'global', 'require', 'saucebase/installer:^3.5.0', '--with-all-dependencies', '--no-interaction']);
        Process::assertRan(fn ($process) => $process->command === [PHP_BINARY, '/usr/local/bin/saucebase', 'new', 'acme', '--no-interaction']
            && ($process->environment['SAUCEBASE_UPDATED'] ?? null) === '1');
    }

    public function test_the_command_run_again_after_an_update_never_updates_again(): void
    {
        putenv('SAUCEBASE_UPDATED=1');

        try {
            $this->assertNull($this->command(current: '3.2.2', latest: '3.5.0')->check());
            Process::assertNothingRan();
        } finally {
            putenv('SAUCEBASE_UPDATED');
        }
    }

    public function test_a_failed_update_carries_on_with_a_warning_when_only_a_minor_behind(): void
    {
        Process::fake(["'composer' *" => Process::result(errorOutput: 'offline', exitCode: 1)]);
        $command = $this->command(current: '3.2.2', latest: '3.5.0');

        $this->assertNull($command->check());

        Process::assertDidntRun(fn ($process) => str_contains(implode(' ', (array) $process->command), ' new '));
        $this->assertStringContainsString('composer global require saucebase/installer:^3.5.0', $command->output());
    }

    public function test_a_failed_update_stops_a_major_version_behind(): void
    {
        Process::fake(["'composer' *" => Process::result(exitCode: 1)]);

        $this->assertSame(NewCommand::FAILURE, $this->command(current: '2.9.0', latest: '3.5.0')->check());
    }

    public function test_an_up_to_date_installer_runs_nothing(): void
    {
        $this->assertNull($this->command(current: '3.5.0', latest: '3.5.0')->check());

        Process::assertNothingRan();
    }

    private function command(string $current, string $latest): object
    {
        $command = new class($current, $latest) extends NewCommand
        {
            public function __construct(private string $current, private string $latest)
            {
                parent::__construct();
            }

            public function check(): ?int
            {
                return $this->checkForUpdates();
            }

            public function output(): string
            {
                return $this->output->getOutput()->fetch();
            }

            protected function currentVersion(): string
            {
                return $this->current;
            }

            protected function latestVersion(): ?string
            {
                return $this->latest;
            }

            protected function rerunArguments(): array
            {
                return ['/usr/local/bin/saucebase', 'new', 'acme', '--no-interaction'];
            }
        };

        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        return $command;
    }
}

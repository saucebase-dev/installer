<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Tests\Feature\TestableInstallCommand;
use Saucebase\Installer\Tests\TestCase;

/** A quiet container command keeps a task line clean, and still shows why it failed. */
class DockerQuietExecTest extends TestCase
{
    /** @return array{0: bool, 1: string[]} */
    private function execQuietly(string $output, string $errorOutput = '', int $exitCode = 0): array
    {
        Process::fake(["'docker' 'compose' 'exec' *" => Process::result($output, $errorOutput, $exitCode)]);

        $command = new class extends TestableInstallCommand
        {
            /** @var string[] */
            public array $printed = [];

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->printed[] = $string;
            }
        };
        $command->fakeOptions = ['path' => sys_get_temp_dir()];

        $docker = new class extends DockerEnvironment
        {
            public function quietly(InstallCommand $command): bool
            {
                return $this->execInContainer($command, ['php', 'artisan', 'migrate'], quiet: true);
            }
        };

        return [$docker->quietly($command), $command->printed];
    }

    public function test_a_quiet_command_prints_nothing_when_it_succeeds(): void
    {
        [$ok, $printed] = $this->execQuietly('Seeding database.');

        $this->assertTrue($ok);
        $this->assertSame([], $printed);
    }

    public function test_a_quiet_command_prints_its_output_when_it_fails(): void
    {
        [$ok, $printed] = $this->execQuietly('Seeding database.', 'SQLSTATE: table missing', 1);

        $this->assertFalse($ok);
        $this->assertStringContainsString('SQLSTATE: table missing', implode("\n", $printed));
    }

    public function test_the_command_runs_in_the_app_container_from_the_app_folder(): void
    {
        $this->execQuietly('ok');

        Process::assertRan(fn ($process) => $process->command === ['docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'migrate']
            && $process->path === sys_get_temp_dir());
    }
}

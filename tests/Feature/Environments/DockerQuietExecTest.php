<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Tests\Feature\TestableInstallCommand;
use Saucebase\Installer\Tests\TestCase;
use Symfony\Component\Process\Process;

/** A quiet container command keeps a task line clean, and still shows why it failed. */
class DockerQuietExecTest extends TestCase
{
    /** @return array{0: bool, 1: string[]} */
    private function execQuietly(string $script): array
    {
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

        $docker = new class($script) extends DockerEnvironment
        {
            public function __construct(private string $script) {}

            protected function containerProcess(InstallCommand $command, array $args): Process
            {
                return new Process(['sh', '-c', $this->script]);
            }

            public function quietly(InstallCommand $command): bool
            {
                return $this->execInContainer($command, ['php', 'artisan', 'migrate'], quiet: true);
            }
        };

        return [$docker->quietly($command), $command->printed];
    }

    public function test_a_quiet_command_prints_nothing_when_it_succeeds(): void
    {
        [$ok, $printed] = $this->execQuietly('echo "Seeding database."');

        $this->assertTrue($ok);
        $this->assertSame([], $printed);
    }

    public function test_a_quiet_command_prints_its_output_when_it_fails(): void
    {
        [$ok, $printed] = $this->execQuietly('echo "Seeding database."; echo "SQLSTATE: table missing" >&2; exit 1');

        $this->assertFalse($ok);
        $this->assertStringContainsString('SQLSTATE: table missing', implode("\n", $printed));
    }
}

<?php

namespace Saucebase\Installer\Tests\Unit;

use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

/**
 * The installer drives docker, herd, mkcert, npm, composer and git. Under test,
 * a process nobody faked fails the test instead of reaching the real tool.
 */
class ProcessGuardTest extends TestCase
{
    public function test_a_process_no_test_faked_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        Process::run(['docker', 'ps']);
    }

    public function test_the_installer_starts_processes_through_the_fakeable_facade(): void
    {
        Process::fake(['*' => Process::result('done')]);
        $command = new class extends InstallCommand
        {
            public function targetPath(): string
            {
                return '/tmp/app';
            }
        };

        $this->assertTrue($command->runProcess(['composer', 'require', 'saucebase/core']));

        Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'saucebase/core']
            && $process->path === '/tmp/app');
    }
}

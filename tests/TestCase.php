<?php

namespace Saucebase\Installer\Tests;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Saucebase\Installer\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process as SymfonyProcess;

abstract class TestCase extends BaseTestCase
{
    protected ConsoleApplication $console;

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        $this->console = Application::make();

        // A process a test did not fake fails the test instead of reaching the real
        // docker, herd, mkcert, npm, composer or git on the developer's machine.
        // Laravel only refuses strays while faking, hence the empty fake.
        Process::preventStrayProcesses();
        Process::fake([]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    /** Every tool the installer looks up with `which` is installed. */
    protected function fakeToolsInstalled(): void
    {
        Process::fake(["'which' *" => Process::result('/usr/local/bin/tool')]);
    }

    /**
     * Let commands matching the pattern really run, for tests about a tool's real
     * behaviour in a temporary folder (git applying a patch). Patterns match the
     * quoted command line: `'git' *`.
     */
    protected function allowRealProcess(string $pattern): void
    {
        Process::fake([$pattern => function (PendingProcess $pending): ProcessResult {
            $process = is_array($pending->command)
                ? new SymfonyProcess($pending->command, $pending->path, null, null, $pending->timeout)
                : SymfonyProcess::fromShellCommandline($pending->command, $pending->path, null, null, $pending->timeout);
            $process->run();

            return new ProcessResult($process);
        }]);
    }

    /** Replace a registered command with a (usually stubbed) instance for subsequent artisan() calls. */
    protected function bindCommand(Command $command): void
    {
        $this->console->add($command);
    }

    protected function artisan(string $cli): CommandResult
    {
        $input = new StringInput($cli);
        $input->setInteractive(false);
        $output = new BufferedOutput;

        $exitCode = $this->console->run($input, $output);

        return new CommandResult($exitCode, $output->fetch());
    }
}

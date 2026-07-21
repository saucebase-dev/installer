<?php

namespace Saucebase\Installer\Tests;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Saucebase\Installer\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class TestCase extends BaseTestCase
{
    protected ConsoleApplication $console;

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        $this->console = Application::make();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        parent::tearDown();
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

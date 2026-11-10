<?php

namespace Saucebase\Installer\Tests\Unit;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Laravel's task line shows DONE for anything but its own failure value, `false` included. */
class TaskResultTest extends TestCase
{
    public function test_a_step_that_fails_is_shown_as_failed(): void
    {
        [$command, $output] = $this->command();

        $this->assertFalse($command->step(fn () => false));
        $this->assertStringContainsString('FAIL', $output->fetch());
    }

    public function test_a_step_that_succeeds_is_shown_as_done(): void
    {
        [$command, $output] = $this->command();

        $this->assertTrue($command->step(fn () => true));
        $this->assertStringContainsString('DONE', $output->fetch());
    }

    /** @return array{0: object, 1: BufferedOutput} */
    private function command(): array
    {
        $output = new BufferedOutput;
        $style = new OutputStyle(new ArrayInput([]), $output);

        $command = new class($style) extends InstallCommand
        {
            public function __construct(OutputStyle $style)
            {
                parent::__construct();
                $this->output = $style;
                $this->components = new Factory($style);
            }

            public function step(callable $task): bool
            {
                return $this->task('Step', $task);
            }
        };

        return [$command, $output];
    }
}

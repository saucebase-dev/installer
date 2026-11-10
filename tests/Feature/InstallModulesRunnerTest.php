<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

/** Every driver installs modules with the same steps; only where the commands run differs. */
class InstallModulesRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/sb-modules-runner-'.uniqid();
        mkdir($this->tmp);
        touch($this->tmp.'/.env');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function command(): TestableInstallCommand
    {
        $command = new class extends TestableInstallCommand
        {
            /** @param  string[]  $selected */
            public function installWith(array $selected, \Closure $run): bool
            {
                $this->components = new class
                {
                    public function task(string $label, callable $task): void
                    {
                        $task();
                    }

                    public function warn(string $message): void {}
                };

                return InstallCommand::doInstallModules($selected, $run);
            }

            public function newLine($count = 1): static
            {
                return $this;
            }
        };
        $command->fakeOptions = ['path' => $this->tmp, 'demo' => true];

        return $command;
    }

    public function test_every_step_runs_through_the_given_runner(): void
    {
        $command = $this->command();

        $ran = [];
        $ok = $command->installWith(['saucebase/blog', 'saucebase/roadmap'], function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        });

        // Core's saucebase:module-install does the rest: require, patches, migrate, seed.
        $this->assertTrue($ok);
        $this->assertSame(['php artisan saucebase:module-install blog roadmap --no-interaction --demo'], $ran);
        $this->assertStringContainsString('DEMO_PASSWORD=', (string) file_get_contents($this->tmp.'/.env'));
    }

    public function test_a_failed_module_install_fails_the_step(): void
    {
        $ok = $this->command()->installWith(['saucebase/blog'], fn (array $args): bool => false);

        $this->assertFalse($ok);
    }
}

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
        $this->assertSame(['php artisan saucebase:module-install blog roadmap --no-interaction --state=showcase'], $ran);
        $this->assertStringContainsString('DEMO_PASSWORD=', (string) file_get_contents($this->tmp.'/.env'));
    }

    /** Core's template-install requires the template with its modules and copies its stubs; no demo unless asked. */
    public function test_a_template_installs_clean_through_template_install(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'template' => 'saucebase/uptime'];

        $ran = [];
        $command->installWith([], function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        });

        $this->assertSame(['php artisan saucebase:template-install uptime --no-interaction'], $ran);
        // No accounts are seeded, so there is no password to set.
        $this->assertStringNotContainsString('DEMO_PASSWORD=', (string) @file_get_contents($this->tmp.'/.env'));
    }

    /** --demo is the template's demo: its default state, with the password its accounts use. */
    public function test_a_template_with_demo_seeds_its_default_state(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'template' => 'saucebase/uptime', 'demo' => true];

        $ran = [];
        $command->installWith([], function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        });

        $this->assertSame(['php artisan saucebase:template-install uptime --no-interaction --state=default'], $ran);
        $this->assertStringContainsString('DEMO_PASSWORD=', (string) file_get_contents($this->tmp.'/.env'));
    }

    public function test_a_template_state_is_passed_through(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'template' => 'saucebase/uptime', 'state' => 'agency'];

        $ran = [];
        $command->installWith([], function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        });

        $this->assertSame(['php artisan saucebase:template-install uptime --no-interaction --state=agency'], $ran);
    }

    public function test_a_failed_module_install_fails_the_step(): void
    {
        $ok = $this->command()->installWith(['saucebase/blog'], fn (array $args): bool => false);

        $this->assertFalse($ok);
    }
}

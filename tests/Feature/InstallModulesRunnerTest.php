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

            public function makeWith(\Closure $run): bool
            {
                $this->components = new class
                {
                    public function task(string $label, callable $task): void
                    {
                        $task();
                    }

                    public function warn(string $message): void {}
                };

                return InstallCommand::makeTemplate($run);
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

        $this->assertSame(['php artisan saucebase:template-install saucebase/uptime --no-interaction'], $ran);
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

        $this->assertSame(['php artisan saucebase:template-install saucebase/uptime --no-interaction --state=default'], $ran);
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

        $this->assertSame(['php artisan saucebase:template-install saucebase/uptime --no-interaction --state=agency'], $ran);
    }

    /** The app builds a template: core's recipe makes it in modules/, Composer installs it. */
    public function test_make_template_creates_the_template_in_the_app(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'acme/notes'];

        $ran = [];
        $ok = $command->makeWith(function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        });

        $this->assertTrue($ok);
        $this->assertSame([
            'php artisan saucebase:recipe Notes template --vendor=acme --no-interaction',
            'composer update acme/notes --no-interaction',
        ], $ran);
    }

    /** A resumed install: the template is already there, so only Composer runs again. */
    public function test_make_template_resumes_after_the_recipe_ran(): void
    {
        mkdir($this->tmp.'/modules/notes', 0777, true);
        file_put_contents($this->tmp.'/modules/notes/composer.json', '{"name": "acme/notes", "type": "saucebase-template"}');
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'acme/notes'];

        $ran = [];
        $this->assertTrue($command->makeWith(function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return true;
        }));
        $this->assertSame(['composer update acme/notes --no-interaction'], $ran);
    }

    /** A module of that name is not a template made earlier: the recipe runs (and refuses the folder). */
    public function test_make_template_does_not_take_a_module_for_the_template(): void
    {
        mkdir($this->tmp.'/modules/auth', 0777, true);
        file_put_contents($this->tmp.'/modules/auth/composer.json', '{"name": "saucebase/auth", "type": "saucebase-module"}');
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'saucebase/auth'];

        $ran = [];
        $command->makeWith(function (array $args) use (&$ran): bool {
            $ran[] = implode(' ', $args);

            return false;
        });
        $this->assertSame(['php artisan saucebase:recipe Auth template --vendor=saucebase --no-interaction'], $ran);
    }

    public function test_make_template_needs_a_vendor_and_name(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'notes'];
        $this->assertFalse($command->makeWith(fn (array $args): bool => true));

        // The name becomes a folder in kebab case and a PHP namespace: anything else is refused.
        foreach (['acme/notes.v2', 'acme/123', 'acme/foo/bar', 'acme/Notes', 'acme/notes--x', 'acme/notes-2'] as $name) {
            $command->fakeOptions = ['path' => $this->tmp, 'make-template' => $name];
            $this->assertFalse($command->makeWith(fn (array $args): bool => true), $name);
        }

        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'acme/my_notes'];

        $ran = [];
        $this->assertFalse($command->makeWith(function (array $args) use (&$ran): bool {
            $ran[] = $args;

            return true;
        }));
        $this->assertSame([], $ran);
    }

    /** An app takes one template: installed from one, or building one. */
    public function test_make_template_refuses_alongside_a_template(): void
    {
        $command = $this->command();
        $command->fakeOptions = ['path' => $this->tmp, 'make-template' => 'acme/notes', 'template' => 'saucebase/uptime'];

        $this->assertFalse($command->makeWith(fn (array $args): bool => true));
    }

    public function test_a_failed_module_install_fails_the_step(): void
    {
        $ok = $this->command()->installWith(['saucebase/blog'], fn (array $args): bool => false);

        $this->assertFalse($ok);
    }
}

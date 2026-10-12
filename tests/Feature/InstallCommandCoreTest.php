<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

/**
 * A new application starts as plain Laravel. Saucebase goes into it the way its
 * README says: require saucebase/core, then let core publish what the application owns.
 */
class InstallCommandCoreTest extends TestCase
{
    private string $dir;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->dir = sys_get_temp_dir().'/sb-core-'.uniqid();
        $this->files->ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_installs_core_into_a_plain_laravel_application(): void
    {
        $command = $this->command();

        $this->assertTrue($command->installCore());
        $this->assertSame([
            // Composer refuses an unlisted plugin outright when it cannot ask.
            'composer config allow-plugins.saucebase/module-installer true',
            'composer require saucebase/core --no-interaction',
            'php artisan saucebase:install react --force',
        ], $command->ran);
    }

    public function test_it_leaves_an_application_that_already_has_saucebase(): void
    {
        $this->files->put($this->dir.'/saucebase.json', '{"framework": "vue"}');
        $command = $this->command();

        $this->assertTrue($command->installCore());
        $this->assertSame([], $command->ran);
    }

    public function test_it_leaves_a_contributor_checkout_to_the_stack_command(): void
    {
        $command = $this->command(['dev' => true]);

        $this->assertTrue($command->installCore());
        $this->assertSame([], $command->ran);
    }

    public function test_it_stops_at_the_first_step_that_fails(): void
    {
        $command = $this->command();
        $command->failing = 'composer require';

        $this->assertFalse($command->installCore());
        $this->assertCount(2, $command->ran);
    }

    public function test_the_framework_of_the_application_wins_over_the_one_asked_for(): void
    {
        $this->files->put($this->dir.'/saucebase.json', '{"framework": "vue"}');
        $command = $this->command();

        $this->assertSame('vue', $command->exposedCaptureStack('react'));
    }

    public function test_a_module_that_composer_cannot_install_fails_the_install(): void
    {
        $command = $this->command();
        $command->failing = 'composer require';

        $this->assertFalse($command->exposedDoInstallModules(['saucebase/auth']));
    }

    /** @param  array<string, bool|string>  $options */
    private function command(array $options = []): CoreInstallCommand
    {
        $command = new CoreInstallCommand;
        $command->fakeOptions = ['path' => $this->dir, ...$options];
        $command->setSelectedStack('react');

        return $command;
    }
}

class CoreInstallCommand extends TestableInstallCommand
{
    /** @var string[] */
    public array $ran = [];

    public ?string $failing = null;

    public function runProcess(array $command, int $timeout = 300): bool
    {
        $this->ran[] = implode(' ', $command);

        return $this->failing === null || ! str_starts_with(implode(' ', $command), $this->failing);
    }

    public function argument($key = null): ?string
    {
        return $this->asked;
    }

    private ?string $asked = null;

    public function exposedCaptureStack(string $asked): ?string
    {
        $this->asked = $asked;
        $this->captureStack();

        return $this->getSelectedStack();
    }

    /** @param  string[]  $selected */
    public function exposedDoInstallModules(array $selected): bool
    {
        $this->components = new class
        {
            public function task(string $label, callable $task): void
            {
                $task();
            }

            public function warn(string $message): void {}
        };

        return InstallCommand::doInstallModules($selected);
    }

    public function newLine($count = 1): static
    {
        return $this;
    }
}

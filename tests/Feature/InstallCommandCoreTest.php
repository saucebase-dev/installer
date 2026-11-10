<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
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
            'composer require --dev saucebase/laravel-playwright --no-interaction',
            'php artisan saucebase:install react --force --no-interaction',
        ], $command->ran);
    }

    public function test_it_names_the_project_after_its_directory(): void
    {
        $this->files->put($this->dir.'/composer.json', "{\n    \"\$schema\": \"https://getcomposer.org/schema.json\",\n    \"name\": \"laravel/laravel\",\n    \"type\": \"project\"\n}\n");
        $this->files->put($this->dir.'/package.json', "{\n    \"private\": true,\n    \"name\": \"saucebase\",\n    \"type\": \"module\"\n}\n");

        $this->files->ensureDirectoryExists($this->dir.'/public');
        $this->files->put($this->dir.'/public/site.webmanifest', "{\n    \"name\": \"Saucebase\",\n    \"short_name\": \"Saucebase\",\n    \"display\": \"standalone\"\n}\n");

        $this->command()->nameProject();

        $slug = basename($this->dir);
        $name = Str::headline($slug);
        $this->assertStringContainsString("\"name\": \"{$name}\",\n    \"short_name\": \"{$name}\",", $this->files->get($this->dir.'/public/site.webmanifest'));
        $this->assertStringContainsString("\"name\": \"app/{$slug}\",\n    \"type\": \"project\"", $this->files->get($this->dir.'/composer.json'));
        $this->assertStringContainsString("\"name\": \"{$slug}\",\n    \"type\": \"module\"", $this->files->get($this->dir.'/package.json'));
    }

    public function test_the_app_can_be_called_something_other_than_its_directory(): void
    {
        $this->files->ensureDirectoryExists($this->dir.'/public');
        $this->files->put($this->dir.'/public/site.webmanifest', "{\n    \"name\": \"Saucebase\",\n    \"short_name\": \"Saucebase\"\n}\n");
        $this->files->put($this->dir.'/.env', "APP_NAME=Laravel\n");

        $command = $this->command(['app-name' => 'Acme Shop']);
        $command->applyAppIdentity();
        $command->nameProject();

        $this->assertStringContainsString('"name": "Acme Shop"', $this->files->get($this->dir.'/public/site.webmanifest'));
        $this->assertStringContainsString('APP_NAME="Acme Shop"', $this->files->get($this->dir.'/.env'));
    }

    public function test_a_name_with_quotes_or_dollar_signs_is_written_as_valid_json(): void
    {
        $this->files->ensureDirectoryExists($this->dir.'/public');
        $this->files->put($this->dir.'/public/site.webmanifest', "{\n    \"name\": \"Saucebase\",\n    \"short_name\": \"Saucebase\"\n}\n");

        $this->command(['app-name' => 'Big $1 "Deals"'])->nameProject();

        $manifest = json_decode($this->files->get($this->dir.'/public/site.webmanifest'), true);
        $this->assertSame('Big $1 "Deals"', $manifest['name']);
    }

    public function test_it_keeps_a_name_the_project_already_chose(): void
    {
        $this->files->put($this->dir.'/composer.json', "{\n    \"name\": \"acme/shop\"\n}\n");
        $this->files->put($this->dir.'/package.json', "{\n    \"name\": \"shop\"\n}\n");

        $this->command()->nameProject();

        $this->assertStringContainsString('"name": "acme/shop"', $this->files->get($this->dir.'/composer.json'));
        $this->assertStringContainsString('"name": "shop"', $this->files->get($this->dir.'/package.json'));
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

    public function test_a_module_that_cannot_be_installed_fails_the_install(): void
    {
        $command = $this->command();
        $command->failing = 'php artisan saucebase:module-install';

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

    public function runArtisan(array $args, int $timeout = 120): bool
    {
        return $this->runProcess(['php', 'artisan', ...$args], $timeout);
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

    public function info($string, $verbosity = null): void {}

    public function newLine($count = 1): static
    {
        return $this;
    }
}

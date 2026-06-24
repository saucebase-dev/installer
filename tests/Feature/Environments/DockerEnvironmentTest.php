<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Tests\TestCase;

class DockerEnvironmentTest extends TestCase
{
    public function test_name_is_docker(): void
    {
        $this->assertSame('docker', (new DockerEnvironment)->name());
    }

    public function test_label_is_set(): void
    {
        $this->assertNotEmpty((new DockerEnvironment)->label());
    }

    public function test_implements_environment_contract(): void
    {
        $this->assertInstanceOf(Environment::class, new DockerEnvironment);
    }

    // -------------------------------------------------------------------------
    // buildContainerArgs
    // -------------------------------------------------------------------------

    public function test_always_includes_driver_native_and_force_flags(): void
    {
        $args = $this->buildArgs();

        $this->assertContains('--driver=native', $args);
        $this->assertContains('--force', $args);
    }

    public function test_always_includes_artisan_command(): void
    {
        $args = $this->buildArgs();

        $this->assertContains('php', $args);
        $this->assertContains('artisan', $args);
        $this->assertContains('saucebase:install', $args);
    }

    public function test_forwards_stack_argument(): void
    {
        $args = $this->buildArgs(stack: 'vue');

        $this->assertContains('vue', $args);
    }

    public function test_forwards_react_stack(): void
    {
        $args = $this->buildArgs(stack: 'react');

        $this->assertContains('react', $args);
    }

    public function test_omits_stack_when_null(): void
    {
        $args = $this->buildArgs(stack: null);

        $this->assertNotContains('vue', $args);
        $this->assertNotContains('react', $args);
    }

    public function test_forwards_selected_modules_as_comma_list(): void
    {
        $args = $this->buildArgs(modules: ['saucebase/auth', 'saucebase/billing']);

        $this->assertContains('--modules=saucebase/auth,saucebase/billing', $args);
    }

    public function test_omits_modules_arg_when_none_selected(): void
    {
        $args = $this->buildArgs(modules: []);

        $matched = array_filter($args, fn (string $a) => str_starts_with($a, '--modules='));
        $this->assertEmpty($matched);
    }

    public function test_forwards_fresh_flag(): void
    {
        $args = $this->buildArgs(options: ['fresh' => true]);

        $this->assertContains('--fresh', $args);
    }

    public function test_omits_fresh_when_not_set(): void
    {
        $args = $this->buildArgs(options: ['fresh' => false]);

        $this->assertNotContains('--fresh', $args);
    }

    public function test_forwards_dev_flag(): void
    {
        $args = $this->buildArgs(options: ['dev' => true]);

        $this->assertContains('--dev', $args);
    }

    public function test_forwards_all_modules_flag(): void
    {
        $args = $this->buildArgs(options: ['all-modules' => true]);

        $this->assertContains('--all-modules', $args);
    }

    public function test_multiple_flags_are_all_forwarded(): void
    {
        $args = $this->buildArgs(
            stack: 'react',
            modules: ['saucebase/auth'],
            options: ['fresh' => true, 'dev' => true],
        );

        $this->assertContains('react', $args);
        $this->assertContains('--modules=saucebase/auth', $args);
        $this->assertContains('--fresh', $args);
        $this->assertContains('--dev', $args);
        $this->assertContains('--driver=native', $args);
        $this->assertContains('--force', $args);
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites
    // -------------------------------------------------------------------------

    public function test_missing_prerequisites_returns_empty_when_all_tools_present(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool { return true; }

            protected function dockerComposeAvailable(): bool { return true; }
        };

        $this->assertSame([], $env->missingPrerequisites());
    }

    public function test_missing_prerequisites_reports_docker_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool { return $name !== 'docker'; }

            protected function dockerComposeAvailable(): bool { return true; }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('docker', $missing[0]);
    }

    public function test_missing_prerequisites_reports_docker_compose_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool { return true; }

            protected function dockerComposeAvailable(): bool { return false; }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('docker compose', $missing[0]);
    }

    public function test_missing_prerequisites_reports_npm_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool { return $name !== 'npm'; }

            protected function dockerComposeAvailable(): bool { return true; }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('npm', $missing[0]);
    }

    public function test_missing_prerequisites_skips_compose_check_when_docker_itself_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool { return false; }

            protected function dockerComposeAvailable(): bool { return false; }
        };

        $missing = $env->missingPrerequisites();
        // docker + npm missing; docker compose check is skipped via elseif
        $this->assertCount(2, $missing);
        $this->assertStringContainsString('docker', $missing[0]);
        $this->assertStringContainsString('npm', $missing[1]);
    }

    // -------------------------------------------------------------------------
    // run() failure propagation
    // -------------------------------------------------------------------------

    public function test_run_returns_failure_and_skips_composer_when_docker_fails_to_start(): void
    {
        $spy = (object) ['composerCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function publishStubs(InstallCommand $command): void {}

            protected function generateSsl(InstallCommand $command): void {}

            protected function setDockerEnvDefaults(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return false;
            }

            protected function runComposerInContainer(InstallCommand $command): bool
            {
                $this->spy->composerCalled = true;

                return true;
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result);
        $this->assertFalse($spy->composerCalled, 'composer must not run when Docker fails to start');
    }

    public function test_run_returns_failure_when_composer_install_fails_in_container(): void
    {
        $spy = (object) ['installCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function publishStubs(InstallCommand $command): void {}

            protected function generateSsl(InstallCommand $command): void {}

            protected function setDockerEnvDefaults(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return true;
            }

            protected function runComposerInContainer(InstallCommand $command): bool
            {
                return false;
            }

            protected function runInstallInContainer(InstallCommand $command): void
            {
                $this->spy->installCalled = true;
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result);
        $this->assertFalse($spy->installCalled, 'in-container install must not run when composer install fails');
    }

    // -------------------------------------------------------------------------
    // applyDockerEnvDefaults
    // -------------------------------------------------------------------------

    public function test_replaces_sqlite_connection_with_mysql(): void
    {
        $result = $this->applyDefaults("DB_CONNECTION=sqlite\n");

        $this->assertStringContainsString('DB_CONNECTION=mysql', $result);
        $this->assertStringNotContainsString('DB_CONNECTION=sqlite', $result);
    }

    public function test_leaves_mysql_connection_unchanged(): void
    {
        $input = "DB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=myapp\nDB_USERNAME=myapp\nDB_PASSWORD=secret\nMAIL_MAILER=smtp\n";
        $result = $this->applyDefaults($input);

        $this->assertSame($input, $result);
    }

    public function test_appends_db_connection_when_missing(): void
    {
        $result = $this->applyDefaults("APP_NAME=Test\n");

        $this->assertStringContainsString('DB_CONNECTION=mysql', $result);
    }

    public function test_uses_app_slug_for_db_database_and_username(): void
    {
        $result = $this->applyDefaults("APP_SLUG=myproject\nDB_CONNECTION=sqlite\n");

        $this->assertStringContainsString('DB_DATABASE=myproject', $result);
        $this->assertStringContainsString('DB_USERNAME=myproject', $result);
    }

    public function test_falls_back_to_saucebase_slug_when_app_slug_missing(): void
    {
        $result = $this->applyDefaults("DB_CONNECTION=sqlite\n");

        $this->assertStringContainsString('DB_DATABASE=saucebase', $result);
        $this->assertStringContainsString('DB_USERNAME=saucebase', $result);
    }

    public function test_sets_blank_db_vars_to_defaults(): void
    {
        $input = "DB_CONNECTION=sqlite\nDB_HOST=\nDB_PORT=\nDB_DATABASE=\nDB_USERNAME=\nDB_PASSWORD=\n";
        $result = $this->applyDefaults($input);

        $this->assertStringContainsString('DB_HOST=mysql', $result);
        $this->assertStringContainsString('DB_PORT=3306', $result);
        $this->assertStringContainsString('DB_PASSWORD=secret', $result);
    }

    public function test_does_not_overwrite_existing_db_values(): void
    {
        $input = "DB_CONNECTION=mysql\nDB_HOST=custom-host\nDB_PORT=3307\nDB_DATABASE=mydb\nDB_USERNAME=myuser\nDB_PASSWORD=mypass\n";
        $result = $this->applyDefaults($input);

        $this->assertStringContainsString('DB_HOST=custom-host', $result);
        $this->assertStringContainsString('DB_PORT=3307', $result);
        $this->assertStringContainsString('DB_DATABASE=mydb', $result);
        $this->assertStringContainsString('DB_USERNAME=myuser', $result);
        $this->assertStringContainsString('DB_PASSWORD=mypass', $result);
    }

    public function test_appends_missing_db_vars(): void
    {
        $result = $this->applyDefaults("DB_CONNECTION=sqlite\n");

        $this->assertStringContainsString('DB_HOST=mysql', $result);
        $this->assertStringContainsString('DB_PORT=3306', $result);
        $this->assertStringContainsString('DB_PASSWORD=secret', $result);
    }

    public function test_replaces_log_mailer_with_smtp(): void
    {
        $result = $this->applyDefaults("MAIL_MAILER=log\n");

        $this->assertStringContainsString('MAIL_MAILER=smtp', $result);
        $this->assertStringNotContainsString('MAIL_MAILER=log', $result);
    }

    public function test_leaves_smtp_mailer_unchanged(): void
    {
        $input = "MAIL_MAILER=smtp\n";
        $result = $this->applyDefaults($input);

        $this->assertStringContainsString('MAIL_MAILER=smtp', $result);
    }

    public function test_appends_mail_mailer_when_missing(): void
    {
        $result = $this->applyDefaults("APP_NAME=Test\n");

        $this->assertStringContainsString('MAIL_MAILER=smtp', $result);
    }

    public function test_real_env_example_pattern_produces_valid_docker_env(): void
    {
        $input = implode("\n", [
            'APP_SLUG=acme',
            'DB_CONNECTION=sqlite',
            '# DB_HOST=localhost',
            '# DB_DATABASE=${APP_SLUG}',
            '# DB_USERNAME=${APP_SLUG}',
            '# DB_PASSWORD=secret',
            'MAIL_MAILER=log',
            '',
        ]);

        $result = $this->applyDefaults($input);

        $this->assertStringContainsString('DB_CONNECTION=mysql', $result);
        $this->assertStringContainsString('DB_DATABASE=acme', $result);
        $this->assertStringContainsString('DB_USERNAME=acme', $result);
        $this->assertStringContainsString('DB_HOST=mysql', $result);
        $this->assertStringContainsString('DB_PASSWORD=secret', $result);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function applyDefaults(string $env): string
    {
        $exposed = new class extends DockerEnvironment
        {
            public function applyDockerEnvDefaults(string $env): string
            {
                return parent::applyDockerEnvDefaults($env);
            }
        };

        return $exposed->applyDockerEnvDefaults($env);
    }

    /**
     * @param  string[]  $modules
     * @param  array<string, bool>  $options
     * @return string[]
     */
    private function buildArgs(
        ?string $stack = null,
        array $modules = [],
        array $options = [],
    ): array {
        $command = new FakeInstallCommand($stack, $modules, $options);

        return (new DockerEnvironment)->buildContainerArgs($command);
    }
}

/**
 * Minimal InstallCommand stub for testing buildContainerArgs() without
 * needing a real Symfony Console input binding.
 *
 * @internal
 */
class FakeInstallCommand extends InstallCommand
{
    /**
     * @param  string[]  $modules
     * @param  array<string, bool>  $options
     */
    public function __construct(
        private ?string $stack,
        private array $modules,
        private array $fakeOptions,
    ) {}

    public function getSelectedStack(): ?string
    {
        return $this->stack;
    }

    /** @return string[] */
    public function getSelectedModules(): array
    {
        return $this->modules;
    }

    public function option($key = null): string|array|bool|null
    {
        if ($key !== null) {
            return $this->fakeOptions[$key] ?? false;
        }

        return $this->fakeOptions;
    }
}

<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Environments\Environment;
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

    public function test_extends_environment_base(): void
    {
        $this->assertInstanceOf(Environment::class, new DockerEnvironment);
    }

    // -------------------------------------------------------------------------
    // resolveModules
    // -------------------------------------------------------------------------

    public function test_resolve_modules_returns_selected_modules_when_no_option_set(): void
    {
        $modules = $this->resolveModules(modules: ['saucebase/auth', 'saucebase/billing']);

        $this->assertSame(['saucebase/auth', 'saucebase/billing'], $modules);
    }

    public function test_resolve_modules_parses_modules_option(): void
    {
        $modules = $this->resolveModules(options: ['modules' => 'saucebase/auth, saucebase/billing']);

        $this->assertSame(['saucebase/auth', 'saucebase/billing'], $modules);
    }

    public function test_resolve_modules_returns_empty_when_nothing_selected(): void
    {
        $modules = $this->resolveModules(modules: []);

        $this->assertSame([], $modules);
    }

    public function test_resolve_modules_normalizes_short_names_to_saucebase_vendor(): void
    {
        $modules = $this->resolveModules(options: ['modules' => 'auth, billing']);

        $this->assertSame(['saucebase/auth', 'saucebase/billing'], $modules);
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites
    // -------------------------------------------------------------------------

    public function test_missing_prerequisites_returns_empty_when_all_tools_present(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return true;
            }

            protected function dockerComposeAvailable(): bool
            {
                return true;
            }
        };

        $this->assertSame([], $env->missingPrerequisites());
    }

    public function test_missing_prerequisites_reports_docker_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return $name !== 'docker';
            }

            protected function dockerComposeAvailable(): bool
            {
                return true;
            }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('docker', $missing[0]);
    }

    public function test_missing_prerequisites_reports_docker_compose_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return true;
            }

            protected function dockerComposeAvailable(): bool
            {
                return false;
            }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('docker compose', $missing[0]);
    }

    public function test_missing_prerequisites_reports_npm_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return $name !== 'npm';
            }

            protected function dockerComposeAvailable(): bool
            {
                return true;
            }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('npm', $missing[0]);
    }

    public function test_missing_prerequisites_skips_compose_check_when_docker_itself_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return false;
            }

            protected function dockerComposeAvailable(): bool
            {
                return false;
            }
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

    public function test_run_returns_failure_when_ssl_requested_but_mkcert_missing(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function promptForSsl(InstallCommand $command): void
            {
                $this->ssl = true;
            }

            protected function commandExists(string $name): bool
            {
                return $name !== 'mkcert';
            }

            protected function dockerComposeAvailable(): bool
            {
                return true;
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result);
    }

    public function test_run_skips_mkcert_check_when_ssl_disabled(): void
    {
        $spy = (object) ['publishCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function promptForSsl(InstallCommand $command): void
            {
                $this->ssl = false;
            }

            protected function commandExists(string $name): bool
            {
                return false; // mkcert missing — but ssl is off so should not matter
            }

            protected function publishStubs(InstallCommand $command): void
            {
                $this->spy->publishCalled = true;
            }

            protected function generateSsl(InstallCommand $command): void {}

            protected function setDockerEnvDefaults(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return false; // stop here — we just need to confirm it passed the mkcert gate
            }
        };

        $result = $env->run(new FakeInstallCommand(null, [], []));

        $this->assertSame(Command::FAILURE, $result); // failed at startDocker, not mkcert
        $this->assertTrue($spy->publishCalled, 'publishStubs must be reached when ssl is disabled');
    }

    public function test_run_returns_failure_and_skips_composer_when_docker_fails_to_start(): void
    {
        $spy = (object) ['composerCalled' => false];

        $env = new class($spy) extends DockerEnvironment
        {
            public function __construct(private object $spy) {}

            protected function promptForSsl(InstallCommand $command): void {}

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

            protected function promptForSsl(InstallCommand $command): void {}

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
    // generateAppKey idempotency
    // -------------------------------------------------------------------------

    public function test_generate_app_key_skips_when_key_already_set(): void
    {
        $spy = (object) ['execCalled' => false];
        $envPath = base_path('.env');
        file_put_contents($envPath, "APP_KEY=base64:abc123==\n");

        try {
            $env = new class($spy) extends DockerEnvironment
            {
                public function __construct(private object $spy) {}

                protected function execInContainer(InstallCommand $command, array $args, int $timeout = 120): bool
                {
                    $this->spy->execCalled = true;

                    return true;
                }

                public function exposedGenerateAppKey(InstallCommand $command): bool
                {
                    return $this->generateAppKey($command);
                }
            };

            $result = $env->exposedGenerateAppKey(new FakeInstallCommand(null, [], []));

            $this->assertTrue($result);
            $this->assertFalse($spy->execCalled, 'key:generate must not run when APP_KEY is already set');
        } finally {
            @unlink($envPath);
        }
    }

    public function test_generate_app_key_runs_when_key_missing(): void
    {
        $spy = (object) ['execCalled' => false];
        $envPath = base_path('.env');
        file_put_contents($envPath, "APP_NAME=Test\n");

        try {
            $env = new class($spy) extends DockerEnvironment
            {
                public function __construct(private object $spy) {}

                protected function execInContainer(InstallCommand $command, array $args, int $timeout = 120): bool
                {
                    $this->spy->execCalled = true;

                    return true;
                }

                public function exposedGenerateAppKey(InstallCommand $command): bool
                {
                    return $this->generateAppKey($command);
                }
            };

            $env->exposedGenerateAppKey(new FakeInstallCommand(null, [], []));

            $this->assertTrue($spy->execCalled, 'key:generate must run when APP_KEY is not set');
        } finally {
            @unlink($envPath);
        }
    }

    // -------------------------------------------------------------------------
    // installModules failure propagation
    // -------------------------------------------------------------------------

    public function test_boot_returns_failure_when_install_modules_fails(): void
    {
        $env = new class extends DockerEnvironment
        {
            protected function beforePrompts(InstallCommand $command): ?int
            {
                return null;
            }

            protected function publishStubs(InstallCommand $command): void {}

            protected function generateSsl(InstallCommand $command): void {}

            protected function setDockerEnvDefaults(InstallCommand $command): void {}

            protected function startDocker(InstallCommand $command): bool
            {
                return true;
            }

            protected function runComposerInContainer(InstallCommand $command): bool
            {
                return true;
            }

            protected function generateAppKey(InstallCommand $command): bool
            {
                return true;
            }

            protected function runMigrations(InstallCommand $command): bool
            {
                return true;
            }

            protected function runStack(InstallCommand $command): void {}

            protected function installModules(InstallCommand $command): bool
            {
                return false;
            }
        };

        $command = new class extends FakeInstallCommand
        {
            public function __construct()
            {
                parent::__construct(null, [], []);
            }

            public function ensureEnvFile(): bool
            {
                return true;
            }

            public function promptForModules(): void {}

            public function displaySuccess(array $steps = []): void {}

            public function rewriteCrossModuleImports(): void {}
        };

        $result = $env->run($command);
        $this->assertSame(Command::FAILURE, $result);
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
        $input = "APP_URL=https://localhost\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=myapp\nDB_USERNAME=myapp\nDB_PASSWORD=secret\nMAIL_MAILER=smtp\n";
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

    public function test_sets_https_url_when_ssl_enabled(): void
    {
        $result = $this->applyDefaults("APP_URL=http://localhost\n", ssl: true);

        $this->assertStringContainsString('APP_URL=https://localhost', $result);
        $this->assertStringNotContainsString('APP_URL=http://localhost', $result);
    }

    public function test_sets_http_url_when_ssl_disabled(): void
    {
        $result = $this->applyDefaults("APP_URL=https://localhost\n", ssl: false);

        $this->assertStringContainsString('APP_URL=http://localhost', $result);
        $this->assertStringNotContainsString('APP_URL=https://localhost', $result);
    }

    public function test_replaces_http_localhost_with_port_when_ssl_enabled(): void
    {
        $result = $this->applyDefaults("APP_URL=http://localhost:8000\n", ssl: true);

        $this->assertStringContainsString('APP_URL=https://localhost', $result);
        $this->assertStringNotContainsString('http://localhost:8000', $result);
    }

    public function test_appends_https_url_when_missing_and_ssl_enabled(): void
    {
        $result = $this->applyDefaults("APP_NAME=Test\n", ssl: true);

        $this->assertStringContainsString('APP_URL=https://localhost', $result);
    }

    public function test_appends_http_url_when_missing_and_ssl_disabled(): void
    {
        $result = $this->applyDefaults("APP_NAME=Test\n", ssl: false);

        $this->assertStringContainsString('APP_URL=http://localhost', $result);
    }

    public function test_leaves_custom_app_url_unchanged_regardless_of_ssl(): void
    {
        $input = "APP_URL=https://myapp.test\n";

        $this->assertStringContainsString('APP_URL=https://myapp.test', $this->applyDefaults($input, ssl: true));
        $this->assertStringContainsString('APP_URL=https://myapp.test', $this->applyDefaults($input, ssl: false));
    }

    public function test_real_env_example_pattern_produces_valid_docker_env(): void
    {
        $input = implode("\n", [
            'APP_SLUG=acme',
            'APP_URL=http://localhost',
            'DB_CONNECTION=sqlite',
            '# DB_HOST=localhost',
            '# DB_DATABASE=${APP_SLUG}',
            '# DB_USERNAME=${APP_SLUG}',
            '# DB_PASSWORD=secret',
            'MAIL_MAILER=log',
            '',
        ]);

        $result = $this->applyDefaults($input, ssl: true);

        $this->assertStringContainsString('DB_CONNECTION=mysql', $result);
        $this->assertStringContainsString('DB_DATABASE=acme', $result);
        $this->assertStringContainsString('DB_USERNAME=acme', $result);
        $this->assertStringContainsString('DB_HOST=mysql', $result);
        $this->assertStringContainsString('DB_PASSWORD=secret', $result);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $result);
        $this->assertStringContainsString('APP_URL=https://localhost', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function applyDefaults(string $env, bool $ssl = true): string
    {
        $exposed = new class extends DockerEnvironment
        {
            public function applyDockerEnvDefaults(string $env, bool $ssl = true): string
            {
                return parent::applyDockerEnvDefaults($env, $ssl);
            }
        };

        return $exposed->applyDockerEnvDefaults($env, $ssl);
    }

    /**
     * @param  string[]  $modules
     * @param  array<string, mixed>  $options
     * @return string[]
     */
    private function resolveModules(
        array $modules = [],
        array $options = [],
    ): array {
        $command = new FakeInstallCommand(null, $modules, $options);

        $exposed = new class extends DockerEnvironment
        {
            public function resolveModules(InstallCommand $command): array
            {
                return parent::resolveModules($command);
            }
        };

        return $exposed->resolveModules($command);
    }
}

/**
 * Minimal InstallCommand stub for DockerEnvironment tests.
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

    public function error($string, $verbosity = null): void {}

    public function line($string, $style = null, $verbosity = null): void {}

    public function info($string, $verbosity = null): void {}

    public function warn($string, $verbosity = null): void {}
}

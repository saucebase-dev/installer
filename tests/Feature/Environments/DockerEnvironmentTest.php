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
        $appDir = sys_get_temp_dir().'/sb-docker-test-'.uniqid();
        mkdir($appDir, 0755, true);
        $envPath = $appDir.'/.env';
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

            $result = $env->exposedGenerateAppKey(new FakeInstallCommand(null, [], ['path' => $appDir]));

            $this->assertTrue($result);
            $this->assertFalse($spy->execCalled, 'key:generate must not run when APP_KEY is already set');
        } finally {
            @unlink($envPath);
            @rmdir($appDir);
        }
    }

    public function test_generate_app_key_runs_when_key_missing(): void
    {
        $spy = (object) ['execCalled' => false];
        $appDir = sys_get_temp_dir().'/sb-docker-test-'.uniqid();
        mkdir($appDir, 0755, true);
        $envPath = $appDir.'/.env';
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

            $env->exposedGenerateAppKey(new FakeInstallCommand(null, [], ['path' => $appDir]));

            $this->assertTrue($spy->execCalled, 'key:generate must run when APP_KEY is not set');
        } finally {
            @unlink($envPath);
            @rmdir($appDir);
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
    // Port conflict detection
    // -------------------------------------------------------------------------

    /** Verbatim `docker ps` output from a machine running a conflicting Saucebase stack. */
    private const DOCKER_PS = <<<'OUTPUT'
        |whatsthere-queue-1|whatsthere
        9000/tcp|whatsthere-app-1|whatsthere
        0.0.0.0:6379->6379/tcp, [::]:6379->6379/tcp|whatsthere-redis-1|whatsthere
        0.0.0.0:3306->3306/tcp, [::]:3306->3306/tcp|whatsthere-mysql-1|whatsthere
        0.0.0.0:1025->1025/tcp, [::]:1025->1025/tcp, 0.0.0.0:8025->8025/tcp, [::]:8025->8025/tcp|whatsthere-mailpit-1|whatsthere
        OUTPUT;

    public function test_parses_published_ports_to_their_owning_compose_project(): void
    {
        $owners = $this->exposed()->exposedParseDockerPortOwners(self::DOCKER_PS);

        $this->assertSame(['container' => 'whatsthere-redis-1', 'project' => 'whatsthere'], $owners[6379]);
        $this->assertSame(['container' => 'whatsthere-mysql-1', 'project' => 'whatsthere'], $owners[3306]);
        // One container can publish several ports.
        $this->assertSame(['container' => 'whatsthere-mailpit-1', 'project' => 'whatsthere'], $owners[1025]);
        $this->assertSame(['container' => 'whatsthere-mailpit-1', 'project' => 'whatsthere'], $owners[8025]);
        $this->assertCount(4, $owners);
    }

    public function test_ignores_exposed_but_unpublished_container_ports(): void
    {
        $owners = $this->exposed()->exposedParseDockerPortOwners(self::DOCKER_PS);

        // "9000/tcp" is exposed to the container network only — it binds no host port.
        $this->assertArrayNotHasKey(9000, $owners);
    }

    public function test_parses_a_standalone_container_as_having_no_project(): void
    {
        $owners = $this->exposed()->exposedParseDockerPortOwners("0.0.0.0:6379->6379/tcp|my-redis|\n");

        $this->assertSame(['container' => 'my-redis', 'project' => null], $owners[6379]);
    }

    public function test_parses_empty_docker_output_to_no_owners(): void
    {
        $this->assertSame([], $this->exposed()->exposedParseDockerPortOwners(''));
        $this->assertSame([], $this->exposed()->exposedParseDockerPortOwners("\n  \n"));
    }

    public function test_free_port_skips_ports_in_use_and_ports_already_assigned(): void
    {
        $env = $this->exposed(inUse: [3307, 3308]);

        $this->assertSame(3310, $env->exposedFreePort(3307, taken: [3309]));
    }

    public function test_free_port_returns_zero_when_the_scan_window_is_exhausted(): void
    {
        $env = $this->exposed(inUse: range(8080, 8300));

        $this->assertSame(0, $env->exposedFreePort(8080));
    }

    public function test_check_ports_passes_without_querying_docker_when_nothing_conflicts(): void
    {
        $env = $this->exposed(inUse: []);

        $this->assertTrue($env->exposedCheckPorts(new FakeInstallCommand(null, [], ['path' => '/nonexistent'])));
        $this->assertFalse($env->dockerQueried, 'docker ps must not run when no port conflicts');
    }

    public function test_check_ports_picks_free_alternatives_under_force_without_prompting(): void
    {
        // The reported real-world clash: another stack holding MySQL, Redis and Mailpit.
        $env = $this->exposed(inUse: [3306, 6379, 1025, 8025]);

        $result = $env->exposedCheckPorts(new FakeInstallCommand(null, [], ['path' => '/nonexistent', 'force' => true]));

        $this->assertTrue($result);
        $this->assertSame([
            'FORWARD_DB_PORT' => 3307,
            'FORWARD_REDIS_PORT' => 6380,
            'FORWARD_MAILPIT_PORT' => 1026,
            'FORWARD_MAILPIT_DASHBOARD_PORT' => 8026,
        ], $env->exposedPortOverrides());
        $this->assertTrue($env->envRewritten, 'the .env writer must re-run so overrides are persisted');
        // Free ports are left alone.
        $this->assertArrayNotHasKey('APP_PORT', $env->exposedPortOverrides());
    }

    public function test_a_resumed_install_does_not_treat_its_own_containers_as_conflicts(): void
    {
        // Resuming after a mid-install failure: our own stack is up and holding the
        // ports. Remapping them here would move the whole app on every retry.
        $env = $this->exposed(
            inUse: [3306, 6379],
            owners: [
                3306 => ['container' => 'my-app-mysql-1', 'project' => 'my-app'],
                6379 => ['container' => 'my-app-redis-1', 'project' => 'my-app'],
            ],
        );
        $env->fakeOwnContainers = ['my-app-mysql-1', 'my-app-redis-1', 'my-app-app-1'];

        $result = $env->exposedCheckPorts(new FakeInstallCommand(null, [], ['path' => '/nonexistent', 'force' => true]));

        $this->assertTrue($result);
        $this->assertSame([], $env->exposedPortOverrides(), 'a resume must not remap its own ports');
    }

    public function test_a_foreign_stack_is_still_a_conflict_when_our_own_containers_run(): void
    {
        $env = $this->exposed(
            inUse: [3306, 6379],
            owners: [
                3306 => ['container' => 'my-app-mysql-1', 'project' => 'my-app'],
                6379 => ['container' => 'other-redis-1', 'project' => 'other'],
            ],
        );
        $env->fakeOwnContainers = ['my-app-mysql-1'];

        $env->exposedCheckPorts(new FakeInstallCommand(null, [], ['path' => '/nonexistent', 'force' => true]));

        // Ours is ignored, the foreign one is remapped.
        $this->assertSame(['FORWARD_REDIS_PORT' => 6380], $env->exposedPortOverrides());
    }

    public function test_check_ports_does_not_offer_to_stop_docker_when_a_plain_process_holds_a_port(): void
    {
        $env = $this->exposed(inUse: [6379, 8025], owners: [6379 => ['container' => 'other-redis', 'project' => 'other']]);
        $env->exposedCheckPorts(new FakeInstallCommand(null, [], ['path' => '/nonexistent', 'force' => true]));

        // 8025 has no Docker owner, so stopping "other" would not unblock the install.
        $this->assertSame([], $env->exposedStoppableTargets(['a' => 6379, 'b' => 8025], $env->fakeOwners));
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

    public function test_port_overrides_are_written_to_env(): void
    {
        $result = $this->applyDefaults("APP_NAME=Test\n", ports: ['FORWARD_DB_PORT' => 3307, 'APP_PORT' => 8080]);

        $this->assertStringContainsString('FORWARD_DB_PORT=3307', $result);
        $this->assertStringContainsString('APP_PORT=8080', $result);
    }

    public function test_port_overrides_replace_the_value_that_clashed(): void
    {
        // Unlike the DB defaults, an existing port value must NOT be respected —
        // it is precisely the one that was found to be in use.
        $result = $this->applyDefaults("FORWARD_REDIS_PORT=6379\n", ports: ['FORWARD_REDIS_PORT' => 6380]);

        $this->assertStringContainsString('FORWARD_REDIS_PORT=6380', $result);
        $this->assertStringNotContainsString('FORWARD_REDIS_PORT=6379', $result);
    }

    public function test_app_url_carries_a_non_default_https_port(): void
    {
        $result = $this->applyDefaults("APP_URL=https://localhost\n", ssl: true, ports: ['APP_HTTPS_PORT' => 8443]);

        $this->assertStringContainsString('APP_URL=https://localhost:8443', $result);
    }

    public function test_app_url_carries_a_non_default_http_port_when_ssl_is_disabled(): void
    {
        $result = $this->applyDefaults("APP_URL=http://localhost\n", ssl: false, ports: ['APP_PORT' => 8080]);

        $this->assertStringContainsString('APP_URL=http://localhost:8080', $result);
    }

    public function test_app_url_stays_bare_when_the_port_is_the_scheme_default(): void
    {
        $result = $this->applyDefaults("APP_URL=https://localhost\n", ssl: true, ports: ['APP_HTTPS_PORT' => 443]);

        $this->assertStringContainsString('APP_URL=https://localhost'."\n", $result);
    }

    public function test_a_previously_ported_app_url_is_rewritten_to_the_new_port(): void
    {
        // The second pass over .env must correct a port this installer wrote earlier.
        $result = $this->applyDefaults("APP_URL=https://localhost:8443\n", ssl: true, ports: ['APP_HTTPS_PORT' => 8444]);

        $this->assertStringContainsString('APP_URL=https://localhost:8444', $result);
        $this->assertStringNotContainsString('8443', $result);
    }

    public function test_a_custom_app_url_is_left_alone_even_with_port_overrides(): void
    {
        $result = $this->applyDefaults("APP_URL=https://myapp.test\n", ssl: true, ports: ['APP_HTTPS_PORT' => 8443]);

        $this->assertStringContainsString('APP_URL=https://myapp.test', $result);
    }

    // -------------------------------------------------------------------------
    // Custom domain
    // -------------------------------------------------------------------------

    public function test_app_url_is_built_from_app_host(): void
    {
        $result = $this->applyDefaults("APP_HOST=myapp.test\nAPP_URL=http://localhost\n", ssl: true);

        $this->assertStringContainsString('APP_URL=https://myapp.test', $result);
    }

    public function test_app_url_combines_a_custom_host_with_a_remapped_port(): void
    {
        $result = $this->applyDefaults(
            "APP_HOST=myapp.test\nAPP_URL=http://localhost\n",
            ssl: true,
            ports: ['APP_HTTPS_PORT' => 8443],
        );

        $this->assertStringContainsString('APP_URL=https://myapp.test:8443', $result);
    }

    public function test_a_stale_url_for_the_chosen_host_is_corrected(): void
    {
        // Re-run after the port moved: same host, so the scheme/port must be refreshed.
        $result = $this->applyDefaults(
            "APP_HOST=myapp.test\nAPP_URL=http://myapp.test:8080\n",
            ssl: true,
            ports: ['APP_HTTPS_PORT' => 8443],
        );

        $this->assertStringContainsString('APP_URL=https://myapp.test:8443', $result);
    }

    public function test_a_port_persisted_by_an_earlier_run_survives_a_resume(): void
    {
        // Resume: the port keys are already in .env and nothing is overridden this
        // run, so the URL must keep :8443 instead of falling back to 443.
        $result = $this->applyDefaults(
            "APP_HOST=myapp.test\nAPP_HTTPS_PORT=8443\nAPP_URL=https://myapp.test:8443\n",
            ssl: true,
            ports: [],
        );

        $this->assertStringContainsString('APP_URL=https://myapp.test:8443', $result);
    }

    public function test_a_url_for_an_unrelated_host_is_still_left_alone(): void
    {
        $result = $this->applyDefaults("APP_HOST=myapp.test\nAPP_URL=https://staging.example.com\n", ssl: true);

        $this->assertStringContainsString('APP_URL=https://staging.example.com', $result);
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
    // nginx + certificates for a custom domain
    // -------------------------------------------------------------------------

    /** The real published stub, so the substitutions are tested against what ships. */
    private function nginxStub(): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/stubs/docker/docker/nginx.conf');
    }

    public function test_nginx_serves_the_custom_domain_first_then_localhost(): void
    {
        $result = $this->exposed()->exposedApplyNginxSettings($this->nginxStub(), 'myapp.test', 80, 443);

        // Primary name first: the HTTP block redirects using $server_name.
        $this->assertStringContainsString('server_name myapp.test localhost;', $result);
        $this->assertStringNotContainsString('server_name localhost;', $result);
    }

    public function test_nginx_is_untouched_for_a_localhost_install(): void
    {
        $stub = $this->nginxStub();

        $this->assertSame($stub, $this->exposed()->exposedApplyNginxSettings($stub, 'localhost', 80, 443));
    }

    public function test_nginx_follows_a_domain_change_on_a_re_run(): void
    {
        $env = $this->exposed();

        // publishStubs() skips an existing file, so this rewrite is the only thing
        // that keeps a re-installed app's nginx.conf in step with a new domain.
        $first = $env->exposedApplyNginxSettings($this->nginxStub(), 'old.test', 80, 443);
        $second = $env->exposedApplyNginxSettings($first, 'new.test', 80, 443);

        $this->assertStringContainsString('server_name new.test localhost;', $second);
        $this->assertStringNotContainsString('old.test', $second);
    }

    public function test_nginx_reports_the_port_actually_published(): void
    {
        // Without this, a stack moved to 8443 by the port check still tells PHP 443
        // and Laravel generates URLs on the wrong port.
        $result = $this->exposed()->exposedApplyNginxSettings($this->nginxStub(), 'localhost', 8080, 8443);

        $this->assertStringContainsString('fastcgi_param SERVER_PORT 8443;', $result);
        $this->assertStringNotContainsString('SERVER_PORT 443;', $result);
    }

    public function test_nginx_no_ssl_stub_gets_the_http_port(): void
    {
        $stub = file_get_contents(dirname(__DIR__, 3).'/stubs/docker/docker/nginx-no-ssl.conf');
        $result = $this->exposed()->exposedApplyNginxSettings($stub, 'localhost', 8080, 8443);

        $this->assertStringContainsString('fastcgi_param SERVER_PORT 8080;', $result);
    }

    public function test_nginx_leaves_a_user_added_vhost_alone(): void
    {
        $conf = $this->nginxStub()."\n".implode("\n", [
            'server {',
            '    listen 8080;',
            '    server_name shop.example.com;',
            '}',
        ]);

        $result = $this->exposed()->exposedApplyNginxSettings($conf, 'myapp.test', 80, 443);

        // Only the installer's own blocks (which always name localhost) are rewritten.
        $this->assertStringContainsString('server_name shop.example.com;', $result);
        $this->assertStringContainsString('server_name myapp.test localhost;', $result);
    }

    public function test_nginx_redirect_carries_a_remapped_https_port(): void
    {
        $result = $this->exposed()->exposedApplyNginxSettings($this->nginxStub(), 'myapp.test', 8080, 8443);

        // $server_name has no port, so the redirect would otherwise land on 443.
        $this->assertStringContainsString('https://$server_name:8443$request_uri', $result);
    }

    public function test_nginx_redirect_stays_bare_on_the_default_port(): void
    {
        $result = $this->exposed()->exposedApplyNginxSettings($this->nginxStub(), 'myapp.test', 80, 443);

        $this->assertStringContainsString('https://$server_name$request_uri', $result);
        $this->assertStringNotContainsString('$server_name:', $result);
    }

    public function test_nginx_redirect_is_retargeted_on_a_re_run(): void
    {
        $first = $this->exposed()->exposedApplyNginxSettings($this->nginxStub(), 'myapp.test', 8080, 8443);
        $second = $this->exposed()->exposedApplyNginxSettings($first, 'myapp.test', 80, 443);

        $this->assertStringContainsString('https://$server_name$request_uri', $second);
        $this->assertStringNotContainsString('8443', $second);
    }

    public function test_certificate_covers_the_custom_domain_and_localhost(): void
    {
        $hosts = $this->exposed()->exposedCertificateHosts('myapp.test');

        $this->assertContains('myapp.test', $hosts);
        $this->assertContains('*.myapp.test', $hosts);
        $this->assertContains('localhost', $hosts);
    }

    public function test_certificate_hosts_do_not_repeat_localhost(): void
    {
        $hosts = $this->exposed()->exposedCertificateHosts('localhost');

        $this->assertSame(array_unique($hosts), $hosts);
        $this->assertSame(['localhost', '*.localhost', '127.0.0.1', '::1'], $hosts);
    }

    // -------------------------------------------------------------------------
    // Failure messaging
    // -------------------------------------------------------------------------

    public function test_run_reports_the_failed_step_and_a_resume_command(): void
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

            protected function checkPorts(InstallCommand $command): bool
            {
                return true;
            }

            protected function startDocker(InstallCommand $command): bool
            {
                return false;
            }
        };

        $command = new class extends FakeInstallCommand
        {
            public ?string $failedStep = null;

            public array $resumeOptions = [];

            public function __construct()
            {
                parent::__construct(null, [], []);
            }

            public function ensureEnvFile(): bool
            {
                return true;
            }

            public function promptForModules(): void {}

            public function displayFailure(?string $step = null, array $resumeOptions = []): void
            {
                $this->failedStep = $step;
                $this->resumeOptions = $resumeOptions;
            }
        };

        $this->assertSame(Command::FAILURE, $env->run($command));
        $this->assertSame('Starting Docker services', $command->failedStep);
        // A resume must not re-prompt for the driver or SSL.
        $this->assertSame(['--driver' => 'docker', '--ssl' => 'yes'], $command->resumeOptions);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A DockerEnvironment with the network probes stubbed out, exposing the pure logic.
     *
     * @param  int[]  $inUse
     * @param  array<int, array{container: string, project: string|null}>  $owners
     */
    private function exposed(array $inUse = [], array $owners = []): object
    {
        return new class($inUse, $owners) extends DockerEnvironment
        {
            public bool $dockerQueried = false;

            public bool $envRewritten = false;

            /** @param  int[]  $inUse */
            public function __construct(private array $inUse, public array $fakeOwners) {}

            protected function portInUse(int $port): bool
            {
                return in_array($port, $this->inUse, true);
            }

            protected function dockerPortOwners(): array
            {
                $this->dockerQueried = true;

                return $this->fakeOwners;
            }

            /** @var string[] Containers this app's own Compose project is running. */
            public array $fakeOwnContainers = [];

            protected function ownContainers(InstallCommand $command): array
            {
                return $this->fakeOwnContainers;
            }

            protected function setDockerEnvDefaults(InstallCommand $command): void
            {
                $this->envRewritten = true;
            }

            public function exposedParseDockerPortOwners(string $psOutput): array
            {
                return $this->parseDockerPortOwners($psOutput);
            }

            /** @param  int[]  $taken */
            public function exposedFreePort(int $base, array $taken = []): int
            {
                return $this->freePort($base, $taken);
            }

            public function exposedCheckPorts(InstallCommand $command): bool
            {
                return $this->checkPorts($command);
            }

            public function exposedStoppableTargets(array $conflicts, array $owners): array
            {
                return $this->stoppableTargets($conflicts, $owners);
            }

            /** @return array<string, int> */
            public function exposedPortOverrides(): array
            {
                return $this->portOverrides;
            }

            public function exposedApplyNginxSettings(string $conf, string $host, int $http, int $https): string
            {
                return $this->applyNginxSettings($conf, $host, $http, $https);
            }

            /** @return string[] */
            public function exposedCertificateHosts(string $host): array
            {
                return $this->certificateHosts($host);
            }

            public function exposedCertCoversHost(string $certFile, string $host): bool
            {
                return $this->certCoversHost($certFile, $host);
            }
        };
    }

    private function applyDefaults(string $env, bool $ssl = true, array $ports = []): string
    {
        $exposed = new class extends DockerEnvironment
        {
            public function applyDockerEnvDefaults(string $env, bool $ssl = true, array $ports = []): string
            {
                return parent::applyDockerEnvDefaults($env, $ssl, $ports);
            }
        };

        return $exposed->applyDockerEnvDefaults($env, $ssl, $ports);
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

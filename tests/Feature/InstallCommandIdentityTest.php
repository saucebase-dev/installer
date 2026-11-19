<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Support\Str;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

class InstallCommandIdentityTest extends TestCase
{
    private function apply(string $env, string $dir = 'my-app', string $host = 'localhost', bool $native = false): string
    {
        $command = new TestableInstallCommand;

        return $command->exposedApplyIdentityToEnv(
            $env,
            Str::headline($dir),
            Str::slug($dir),
            $host,
            $native,
        );
    }

    public function test_names_the_app_after_its_directory(): void
    {
        $result = $this->apply("APP_NAME=\"Saucebase\"\nAPP_SLUG=saucebase\n");

        $this->assertStringContainsString('APP_NAME="My App"', $result);
        $this->assertStringContainsString('APP_SLUG=my-app', $result);
    }

    /** Whatever the name holds reaches .env as written: no regex backreferences, quotes escaped for dotenv. */
    public function test_an_app_name_with_dollars_or_quotes_is_written_as_typed(): void
    {
        $command = new TestableInstallCommand;

        $this->assertStringContainsString('APP_NAME="Shop $0 \\\\1"', $command->exposedApplyIdentityToEnv("APP_NAME=Laravel\n", 'Shop $0 \1', 'shop', 'localhost', false));
        $this->assertStringContainsString('APP_NAME="Joe \"Big\" Shop"', $command->exposedApplyIdentityToEnv("APP_NAME=Laravel\n", 'Joe "Big" Shop', 'joe', 'localhost', false));
        $this->assertSame("KEY=a\$0b\n", InstallCommand::setEnvLine("KEY=old\n", 'KEY', 'a$0b'));
    }

    public function test_names_a_plain_laravel_app_after_its_directory(): void
    {
        $result = $this->apply("APP_NAME=Laravel\n");

        $this->assertStringContainsString('APP_NAME="My App"', $result);
        $this->assertStringContainsString('APP_SLUG=my-app', $result);
    }

    public function test_leaves_a_single_word_name_unquoted(): void
    {
        $result = $this->apply("APP_NAME=Saucebase\n", dir: 'blog');

        $this->assertStringContainsString('APP_NAME=Blog', $result);
        $this->assertStringNotContainsString('"', $result);
    }

    public function test_does_not_clobber_a_name_the_user_has_set(): void
    {
        $result = $this->apply("APP_NAME=\"Acme Corp\"\nAPP_SLUG=acme\n", host: 'localhost');

        $this->assertStringContainsString('APP_NAME="Acme Corp"', $result);
        $this->assertStringContainsString('APP_SLUG=acme', $result);
        $this->assertStringNotContainsString('My App', $result);
    }

    public function test_an_explicit_domain_change_reaches_app_host(): void
    {
        // APP_HOST is installer-owned: the certificate and nginx config are built from
        // the resolved domain, so a stale host here would desynchronise them. The old
        // value is not lost — captureDomain() offers it as the prompt default.
        $result = $this->apply("APP_HOST=old.test\n", host: 'new.test');

        $this->assertStringContainsString('APP_HOST=new.test', $result);
        $this->assertStringNotContainsString('old.test', $result);
    }

    public function test_fills_a_blank_value(): void
    {
        $result = $this->apply("APP_NAME=\nAPP_SLUG=\n");

        $this->assertStringContainsString('APP_NAME="My App"', $result);
        $this->assertStringContainsString('APP_SLUG=my-app', $result);
    }

    public function test_appends_keys_that_are_absent(): void
    {
        $result = $this->apply("APP_ENV=local\n", host: 'my-app.test');

        $this->assertStringContainsString('APP_SLUG=my-app', $result);
        $this->assertStringContainsString('APP_HOST=my-app.test', $result);
    }

    /** Whatever is appended to the file next must not land on the same line. */
    public function test_an_appended_key_ends_its_line(): void
    {
        $this->assertSame("APP_NAME=Acme\nAPP_HOST=localhost\n", InstallCommand::setEnvLine("APP_NAME=Acme\n", 'APP_HOST', 'localhost'));
        $this->assertSame("APP_NAME=Acme\nAPP_HOST=localhost\n", InstallCommand::setEnvLine('APP_NAME=Acme', 'APP_HOST', 'localhost'));
    }

    public function test_records_the_chosen_host(): void
    {
        $result = $this->apply("APP_HOST=localhost\n", host: 'my-app.test');

        $this->assertStringContainsString('APP_HOST=my-app.test', $result);
    }

    public function test_native_sets_app_url_only_for_a_custom_host(): void
    {
        // localhost: leave laravel/installer's http://localhost:8000, which is what
        // `composer dev` actually binds.
        $localhost = $this->apply("APP_URL=http://localhost:8000\n", native: true);
        $this->assertStringContainsString('APP_URL=http://localhost:8000', $localhost);

        // A custom host keeps the port `composer dev` serves on.
        $custom = $this->apply("APP_URL=http://localhost:8000\n", host: 'my-app.test', native: true);
        $this->assertStringContainsString("APP_URL=http://my-app.test:8000\n", $custom);
    }

    /** The URL on the host the installer chose last time is the installer's: it follows a new host. */
    public function test_a_reinstall_with_a_new_host_moves_the_installers_own_url(): void
    {
        $native = $this->apply("APP_HOST=old.test\nAPP_URL=http://old.test:8000\n", host: 'new.test', native: true);
        $this->assertStringContainsString("APP_URL=http://new.test:8000\n", $native);

        // Docker: the host moves here; applyDockerEnvDefaults() then sets scheme and port for it.
        $docker = $this->apply("APP_HOST=old.test\nAPP_URL=https://old.test:8443\n", host: 'new.test', native: false);
        $this->assertStringContainsString("APP_URL=https://new.test:8443\n", $docker);
    }

    public function test_a_url_the_user_set_is_left_alone(): void
    {
        $env = "APP_HOST=old.test\nAPP_URL=https://mine.example\n";

        $this->assertStringContainsString("APP_URL=https://mine.example\n", $this->apply($env, host: 'new.test', native: true));
        $this->assertStringContainsString("APP_URL=https://mine.example\n", $this->apply($env, host: 'new.test', native: false));
    }

    public function test_docker_never_writes_app_url_here(): void
    {
        // Docker's APP_URL is owned by applyDockerEnvDefaults(), the only place that
        // knows the scheme and the published port.
        $result = $this->apply("APP_URL=http://localhost:8000\n", host: 'my-app.test', native: false);

        $this->assertStringContainsString('APP_URL=http://localhost:8000', $result);
    }

    // -------------------------------------------------------------------------
    // Domain normalisation
    // -------------------------------------------------------------------------

    public function test_normalizes_a_pasted_url_to_a_bare_host(): void
    {
        $this->assertSame('my.app.test', InstallCommand::normalizeDomain('https://My.App.test:8443/'));
        $this->assertSame('localhost', InstallCommand::normalizeDomain('  LOCALHOST '));
        $this->assertSame('myapp.test', InstallCommand::normalizeDomain('myapp.test'));
    }

    public function test_rejects_a_value_that_is_not_a_hostname(): void
    {
        $this->assertNull(InstallCommand::normalizeDomain('my app.test'));
        $this->assertNull(InstallCommand::normalizeDomain('https://'));
        $this->assertNull(InstallCommand::normalizeDomain('under_score.test'));
    }

    // -------------------------------------------------------------------------
    // Module / stack compatibility
    // -------------------------------------------------------------------------

}

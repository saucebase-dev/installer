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

        $custom = $this->apply("APP_URL=http://localhost:8000\n", host: 'my-app.test', native: true);
        $this->assertStringContainsString('APP_URL=http://my-app.test', $custom);
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

    private function gate(?string $stack, array $fixtures): TestableInstallCommand
    {
        // Captures output instead of writing it: the gate reports through error()/line(),
        // and no console output is bound to a directly-instantiated command.
        $command = new class extends TestableInstallCommand
        {
            /** @var string[] */
            public array $messages = [];

            public function error($string, $verbosity = null): void
            {
                $this->messages[] = $string;
            }

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->messages[] = $string;
            }
        };

        $command->setSelectedStack($stack);
        $command->frameworkFixtures = $fixtures;

        return $command;
    }

    public function test_accepts_modules_that_support_the_chosen_stack(): void
    {
        $command = $this->gate('react', [
            'saucebase/auth' => ['vue', 'react'],
            'saucebase/settings' => ['react'],
        ]);

        $this->assertTrue($command->assertModulesSupportStack(['saucebase/auth', 'saucebase/settings']));
    }

    public function test_rejects_a_module_that_does_not_support_the_chosen_stack(): void
    {
        $command = $this->gate('react', [
            'saucebase/auth' => ['vue', 'react'],
            'saucebase/billing' => ['vue'],
        ]);

        $this->assertFalse($command->assertModulesSupportStack(['saucebase/auth', 'saucebase/billing']));

        // Must name the offender, not just fail.
        $output = implode("\n", $command->messages);
        $this->assertStringContainsString('saucebase/billing', $output);
        $this->assertStringNotContainsString('saucebase/auth', $output);
    }

    public function test_compatibility_gate_is_a_no_op_without_a_stack_or_modules(): void
    {
        $this->assertTrue($this->gate(null, ['saucebase/billing' => ['vue']])
            ->assertModulesSupportStack(['saucebase/billing']));

        $this->assertTrue($this->gate('react', [])->assertModulesSupportStack([]));
    }
}

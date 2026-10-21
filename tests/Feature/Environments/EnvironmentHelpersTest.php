<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

/**
 * The helpers every environment shares: reading the new app's .env, parsing
 * --modules, and telling the user where to cd.
 */
class EnvironmentHelpersTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/sb-env-helpers-'.uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp).' '.escapeshellarg($this->tmp.'-sibling'));
        parent::tearDown();
    }

    public function test_an_env_value_is_read_without_its_quotes(): void
    {
        file_put_contents($this->tmp.'/.env', "APP_URL=\"https://shop.test\"\nDEMO_PASSWORD='s3cret'\n");

        $this->assertSame('https://shop.test', $this->readEnv('APP_URL'));
        $this->assertSame('s3cret', $this->readEnv('DEMO_PASSWORD'));
    }

    /** APP_URL must not match APP_URL_PREFIX or a commented-out line. */
    public function test_an_env_value_is_matched_by_its_whole_key(): void
    {
        file_put_contents($this->tmp.'/.env', "APP_URL_PREFIX=/admin\n# APP_URL=http://old.test\nAPP_URL=https://shop.test\n");

        $this->assertSame('https://shop.test', $this->readEnv('APP_URL'));
    }

    public function test_a_key_with_regex_characters_is_matched_literally(): void
    {
        file_put_contents($this->tmp.'/.env', "AXB=wrong\nA.B=right\n");

        $this->assertSame('right', $this->readEnv('A.B'));
    }

    public function test_a_missing_env_or_key_reads_as_null(): void
    {
        $this->assertNull($this->readEnv('APP_URL'));

        file_put_contents($this->tmp.'/.env', "APP_NAME=Shop\n");

        $this->assertNull($this->readEnv('APP_URL'));
    }

    public function test_module_names_are_lowercased_and_empty_entries_dropped(): void
    {
        $this->assertSame(
            ['saucebase/auth', 'saucebase/billing', 'acme/reports'],
            $this->modules('Auth,, BILLING ,acme/reports,'),
        );
    }

    public function test_none_selects_no_modules(): void
    {
        $this->assertSame([], $this->modules('none'));
    }

    /** /tmp/app-sibling starts with /tmp/app but is not inside it. */
    public function test_a_sibling_sharing_the_folder_name_prefix_is_given_as_an_absolute_path(): void
    {
        mkdir($this->tmp.'-sibling');
        $cwd = getcwd();
        chdir($this->tmp);

        try {
            $steps = $this->cdStep($this->tmp.'-sibling');
        } finally {
            chdir($cwd);
        }

        $this->assertSame(['cd '.realpath($this->tmp.'-sibling')], $steps);
    }

    public function test_a_demo_install_tells_the_user_the_demo_password(): void
    {
        file_put_contents($this->tmp.'/.env', "DEMO_PASSWORD=s3cret\n");

        $steps = $this->environment()->exposedDemoStep($this->command(demo: true));

        $this->assertCount(1, $steps);
        $this->assertStringContainsString('`s3cret`', $steps[0]);
    }

    public function test_without_demo_content_no_password_is_shown(): void
    {
        file_put_contents($this->tmp.'/.env', "DEMO_PASSWORD=s3cret\n");

        $this->assertSame([], $this->environment()->exposedDemoStep($this->command()));
    }

    private function command(?string $modules = null, ?string $target = null, bool $demo = false): InstallCommand
    {
        return new class($target ?? $this->tmp, $modules, $demo) extends InstallCommand
        {
            public function __construct(private string $target, private ?string $modules, private bool $demo)
            {
                parent::__construct();
            }

            public function targetPath(): string
            {
                return $this->target;
            }

            public function option($key = null): string|array|bool|null
            {
                return match ($key) {
                    'modules' => $this->modules,
                    'demo' => $this->demo ?: null,
                    default => null,
                };
            }

            public function getSelectedStack(): ?string
            {
                return null;
            }

            public function getSelectedModules(): array
            {
                return [];
            }
        };
    }

    private function environment(): NativeEnvironment
    {
        return new class extends NativeEnvironment
        {
            public function exposedReadEnvValue(InstallCommand $command, string $key): ?string
            {
                return $this->readEnvValue($command, $key);
            }

            public function exposedResolveModules(InstallCommand $command): array
            {
                return $this->resolveModules($command);
            }

            public function exposedDemoStep(InstallCommand $command): array
            {
                return $this->demoStep($command);
            }

            public function exposedCdStep(InstallCommand $command): array
            {
                return $this->cdStep($command);
            }
        };
    }

    private function readEnv(string $key): ?string
    {
        return $this->environment()->exposedReadEnvValue($this->command(), $key);
    }

    /** @return string[] */
    private function modules(string $raw): array
    {
        return $this->environment()->exposedResolveModules($this->command($raw));
    }

    /** @return string[] */
    private function cdStep(string $target): array
    {
        return $this->environment()->exposedCdStep($this->command(target: $target));
    }
}

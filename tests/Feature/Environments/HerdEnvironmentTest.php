<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\HerdEnvironment;
use Saucebase\Installer\Tests\TestCase;

class HerdEnvironmentTest extends TestCase
{
    private string $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFinishingSteps();
        $this->app = sys_get_temp_dir().'/acme-shop-'.uniqid();
        mkdir($this->app);
    }

    protected function tearDown(): void
    {
        @unlink($this->app.'/.env');
        @rmdir($this->app);
        parent::tearDown();
    }

    public function test_it_is_the_herd_driver(): void
    {
        $this->assertInstanceOf(HerdEnvironment::class, Environment::make('herd'));
    }

    public function test_it_needs_herd(): void
    {
        $env = new class extends HerdEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return $name !== 'herd';
            }
        };

        $this->assertSame(['herd is not installed or not in PATH.'], $env->missingPrerequisites());
    }

    public function test_it_links_and_secures_the_app_after_installing_it(): void
    {
        $command = $this->command(installs: true, domain: 'shop.test');

        $this->assertSame(Command::SUCCESS, (new HerdEnvironment)->run($command));
        $this->assertSame(['herd link shop', 'herd secure shop'], $command->ran);
        $this->assertStringContainsString('APP_URL=https://shop.test', (string) file_get_contents($this->app.'/.env'));
        $this->assertStringContainsString('APP_HOST=shop.test', (string) file_get_contents($this->app.'/.env'));
    }

    public function test_the_summary_says_herd_already_serves_the_app(): void
    {
        $command = $this->command(installs: true, domain: 'shop.test');
        $env = new class extends HerdEnvironment
        {
            /** @return string[] */
            public function steps(InstallCommand $command): array
            {
                return $this->nextSteps($command);
            }
        };
        $env->run($command);

        $this->assertContains('Herd already serves your app: `https://shop.test`', $env->steps($command));
    }

    public function test_without_ssl_it_only_links_and_serves_over_http(): void
    {
        $command = $this->command(installs: true, ssl: 'no');

        (new HerdEnvironment)->run($command);

        $site = basename($this->app);
        $this->assertSame(["herd link {$site}"], $command->ran);
        $this->assertStringContainsString("APP_URL=http://{$site}.test", (string) file_get_contents($this->app.'/.env'));
    }

    public function test_a_failed_install_is_not_linked(): void
    {
        $command = $this->command(installs: false);

        $this->assertSame(Command::FAILURE, (new HerdEnvironment)->run($command));
        $this->assertSame([], $command->ran);
    }

    private function command(bool $installs, string $domain = 'localhost', ?string $ssl = null): InstallCommand
    {
        return new class($this->app, $installs, $domain, $ssl) extends InstallCommand
        {
            /** @var list<string> */
            public array $ran = [];

            public function __construct(private string $app, private bool $installs, private string $site, private ?string $ssl) {}

            public function targetPath(): string
            {
                return $this->app;
            }

            public function getDomain(): string
            {
                return $this->site;
            }

            public function optionOrNull(string $key): string|array|bool|null
            {
                return $key === 'ssl' ? $this->ssl : null;
            }

            public function install(): int
            {
                file_put_contents($this->app.'/.env', "APP_URL=http://localhost:8000\nAPP_HOST=localhost\n");

                return $this->installs ? Command::SUCCESS : Command::FAILURE;
            }

            public function runProcess(array $command, int $timeout = 300): bool
            {
                $this->ran[] = implode(' ', $command);

                return true;
            }

            public function promptForModules(): void {}

            public function displaySuccess(array $steps = []): void {}

            public function displayFailure(?string $step = null, array $resumeOptions = []): void {}
        };
    }
}

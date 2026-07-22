<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Console\Command;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

class NativeEnvironmentTest extends TestCase
{
    public function test_name_is_native(): void
    {
        $this->assertSame('native', (new NativeEnvironment)->name());
    }

    public function test_label_is_set(): void
    {
        $this->assertNotEmpty((new NativeEnvironment)->label());
    }

    public function test_extends_environment_base(): void
    {
        $this->assertInstanceOf(Environment::class, new NativeEnvironment);
    }

    // -------------------------------------------------------------------------
    // missingPrerequisites
    // -------------------------------------------------------------------------

    public function test_missing_prerequisites_returns_empty_when_composer_present(): void
    {
        $env = new class extends NativeEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return true;
            }
        };

        $this->assertSame([], $env->missingPrerequisites());
    }

    public function test_missing_prerequisites_reports_composer_missing(): void
    {
        $env = new class extends NativeEnvironment
        {
            protected function commandExists(string $name): bool
            {
                return false;
            }
        };

        $missing = $env->missingPrerequisites();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('composer', $missing[0]);
    }

    // -------------------------------------------------------------------------
    // run()
    // -------------------------------------------------------------------------

    public function test_run_delegates_to_install_and_returns_success(): void
    {
        $spy = (object) ['installCalled' => false];

        $command = new class($spy) extends InstallCommand
        {
            public function __construct(public object $spy) {}

            public function promptForModules(): void {}

            public function displaySuccess(array $steps = []): void {}

            public function install(): int
            {
                $this->spy->installCalled = true;

                return Command::SUCCESS;
            }
        };

        $env = new NativeEnvironment;
        $result = $env->run($command);

        $this->assertTrue($spy->installCalled);
        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_run_passes_through_failure_from_install(): void
    {
        $command = new class extends InstallCommand
        {
            public function promptForModules(): void {}

            public function displaySuccess(array $steps = []): void {}

            public function install(): int
            {
                return Command::FAILURE;
            }
        };

        $env = new NativeEnvironment;

        $this->assertSame(Command::FAILURE, $env->run($command));
    }

    // -------------------------------------------------------------------------
    // cdStep — inherited from Environment
    // -------------------------------------------------------------------------

    private string $originalCwd;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCwd = getcwd();
        $this->sandbox = sys_get_temp_dir().'/saucebase-env-test-'.uniqid();
        mkdir($this->sandbox.'/sub/dir', recursive: true);
        chdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirectory($this->sandbox);
        parent::tearDown();
    }

    private function envExposingCdStep(): object
    {
        return new class extends NativeEnvironment
        {
            public function exposedCdStep(InstallCommand $command): array
            {
                return $this->cdStep($command);
            }
        };
    }

    public function test_cd_step_is_empty_when_target_matches_cwd(): void
    {
        $command = new class extends InstallCommand
        {
            public function targetPath(): string
            {
                return getcwd();
            }
        };

        $this->assertSame([], $this->envExposingCdStep()->exposedCdStep($command));
    }

    public function test_cd_step_resolves_nested_relative_target(): void
    {
        $command = new class extends InstallCommand
        {
            public function targetPath(): string
            {
                return './sub/../sub/dir';
            }
        };

        $this->assertSame(
            ['cd `'.realpath($this->sandbox.'/sub/dir').'`'],
            $this->envExposingCdStep()->exposedCdStep($command),
        );
    }

    public function test_cd_step_resolves_absolute_target_outside_cwd(): void
    {
        $elsewhere = sys_get_temp_dir().'/saucebase-env-test-elsewhere-'.uniqid();
        mkdir($elsewhere, recursive: true);

        $command = new class($elsewhere) extends InstallCommand
        {
            public function __construct(private string $elsewhere) {}

            public function targetPath(): string
            {
                return $this->elsewhere;
            }
        };

        $this->assertSame(
            ['cd `'.realpath($elsewhere).'`'],
            $this->envExposingCdStep()->exposedCdStep($command),
        );

        $this->removeDirectory($elsewhere);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

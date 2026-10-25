<?php

namespace Saucebase\Installer\Tests\Feature\Environments;

use Illuminate\Support\Facades\Process;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\DockerEnvironment;
use Saucebase\Installer\Tests\Feature\TestableInstallCommand;
use Saucebase\Installer\Tests\TestCase;

/**
 * Docker's own logs (BuildKit, Composer) stay out of the install unless a step fails, and
 * the queue and SSR containers start last: before the app is migrated and built they crash.
 */
class DockerStartTest extends TestCase
{
    private TestableInstallCommand $command;

    private DockerEnvironment $docker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends TestableInstallCommand
        {
            /** @var string[] */
            public array $printed = [];

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->printed[] = $string;
            }

            public function warn($string, $verbosity = null): void
            {
                $this->printed[] = $string;
            }
        };
        $this->command->fakeOptions = ['path' => sys_get_temp_dir()];

        $this->docker = new class extends DockerEnvironment
        {
            public function start(InstallCommand $command): bool
            {
                return $this->startServices($command);
            }

            public function composer(InstallCommand $command): bool
            {
                return $this->runComposerInContainer($command);
            }

            public function workers(InstallCommand $command, bool $built): void
            {
                $this->startWorkers($command, $built);
            }

            public function steps(InstallCommand $command): array
            {
                return $this->nextSteps($command);
            }
        };
    }

    public function test_it_starts_every_service_but_the_queue_and_ssr_without_printing_dockers_log(): void
    {
        Process::fake(['*' => Process::result('#1 [internal] load build definition')]);

        $this->assertTrue($this->docker->start($this->command));

        Process::assertRan(fn ($p) => $p->command === ['docker', 'compose', 'up', '-d', '--wait', '--build', 'app', 'nginx', 'mysql', 'redis', 'mailpit']);
        $this->assertStringNotContainsString('#1', implode("\n", $this->command->printed));
    }

    public function test_dockers_log_is_shown_when_the_services_fail_to_start(): void
    {
        Process::fake(['*' => Process::result('#1 [internal] load build definition', 'pull access denied', 1)]);

        $this->assertFalse($this->docker->start($this->command));

        $this->assertStringContainsString('pull access denied', implode("\n", $this->command->printed));
    }

    public function test_composer_install_in_the_container_prints_nothing_when_it_succeeds(): void
    {
        Process::fake(['*' => Process::result('Generating optimized autoload files')]);

        $this->assertTrue($this->docker->composer($this->command));

        $this->assertStringNotContainsString('autoload', implode("\n", $this->command->printed));
    }

    public function test_the_queue_and_ssr_start_last(): void
    {
        Process::fake();

        $this->docker->workers($this->command, built: true);

        Process::assertRan(fn ($p) => $p->command === ['docker', 'compose', 'up', '-d', 'queue', 'ssr']);
    }

    public function test_without_a_frontend_build_only_the_queue_starts(): void
    {
        Process::fake();

        $this->docker->workers($this->command, built: false);

        Process::assertRan(fn ($p) => $p->command === ['docker', 'compose', 'up', '-d', 'queue']);
    }

    public function test_a_failed_worker_start_says_how_to_start_them(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'no such service', exitCode: 1)]);

        $this->docker->workers($this->command, built: true);

        $this->assertStringContainsString('Start them with `docker compose up -d queue ssr`', implode("\n", $this->command->printed));
    }

    public function test_the_summary_says_artisan_runs_in_the_container(): void
    {
        $this->assertContains('Run artisan in the container: `docker compose exec app php artisan …`', $this->docker->steps($this->command));
    }
}

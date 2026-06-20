<?php

namespace Saucebase\Installer\Environments;

use Illuminate\Support\Facades\Artisan;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Environments\Contracts\Environment;
use Symfony\Component\Process\Process;

class DockerEnvironment implements Environment
{
    public function name(): string
    {
        return 'docker';
    }

    public function label(): string
    {
        return 'Docker';
    }

    public function missingPrerequisites(): array
    {
        $missing = [];

        if (! $this->commandExists('docker')) {
            $missing[] = 'docker is not installed or not in PATH.';
        } elseif (! $this->dockerComposeAvailable()) {
            $missing[] = '"docker compose" subcommand is not available. Ensure Docker Desktop or a Compose plugin is installed.';
        }

        if (! $this->commandExists('npm')) {
            $missing[] = 'npm is not installed or not in PATH.';
        }

        return $missing;
    }

    public function run(InstallCommand $command): int
    {
        $this->publishStubs($command);
        $this->generateSsl($command);

        if (! $this->startDocker($command)) {
            return InstallCommand::FAILURE;
        }

        if (! $this->runComposerInContainer($command)) {
            return InstallCommand::FAILURE;
        }

        $this->runInstallInContainer($command);
        $this->reloadDocker($command);
        $this->runNpmOnHost($command);

        return InstallCommand::SUCCESS;
    }

    protected function publishStubs(InstallCommand $command): void
    {
        $command->info('Publishing Docker stubs...');
        Artisan::call('vendor:publish', ['--tag' => 'saucebase-docker', '--no-interaction' => true]);
    }

    protected function generateSsl(InstallCommand $command): void
    {
        $certFile = base_path('docker/ssl/app.pem');
        $keyFile = base_path('docker/ssl/app.key.pem');

        if (file_exists($certFile) && file_exists($keyFile)) {
            return;
        }

        if (! $this->commandExists('mkcert')) {
            $command->warn('mkcert not found — skipping SSL generation. HTTPS may not work.');

            return;
        }

        $command->info('Generating SSL certificates...');
        @mkdir(dirname($certFile), 0755, true);

        (new Process(['mkcert', '-install']))->run();

        $cert = new Process([
            'mkcert',
            '-key-file', $keyFile,
            '-cert-file', $certFile,
            '*.localhost', 'localhost', '127.0.0.1', '::1',
        ]);
        $cert->run();

        if (! $cert->isSuccessful()) {
            $command->warn('SSL generation failed. Run mkcert manually if HTTPS is needed.');
        }
    }

    protected function startDocker(InstallCommand $command): bool
    {
        $command->info('Starting Docker services...');
        (new Process(['docker', 'compose', 'restart']))->run();

        $up = new Process(['docker', 'compose', 'up', '-d', '--wait']);
        $up->setTimeout(120);
        $up->run();

        if (! $up->isSuccessful()) {
            $command->error('Docker failed to start: '.$up->getErrorOutput());

            return false;
        }

        return true;
    }

    protected function runComposerInContainer(InstallCommand $command): bool
    {
        $command->info('Installing PHP dependencies...');
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'composer', 'install']);
        $process->setTimeout(300);
        $process->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        if (! $process->isSuccessful()) {
            $command->error('composer install failed inside container.');
        }

        return $process->isSuccessful();
    }

    protected function runInstallInContainer(InstallCommand $command): void
    {
        $args = $this->buildContainerArgs($command);
        $command->info('Running installer in container...');

        $process = new Process(array_merge(['docker', 'compose', 'exec', '-T', 'app'], $args));
        $process->setTimeout(300);
        $process->run(fn ($_type, $buffer) => $command->line(trim($buffer)));
    }

    protected function reloadDocker(InstallCommand $command): void
    {
        $command->info('Reloading container...');
        $process = new Process(['docker', 'compose', 'up', '-d', '--wait']);
        $process->setTimeout(60);
        $process->run();
    }

    protected function runNpmOnHost(InstallCommand $command): void
    {
        $command->info('Installing frontend dependencies...');
        $install = new Process(['npm', 'install'], base_path());
        $install->setTimeout(300);
        $install->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        if (! $install->isSuccessful()) {
            $command->warn('npm install failed. Run manually: npm install');

            return;
        }

        $command->info('Building frontend assets...');
        $build = new Process(['npm', 'run', 'build'], base_path());
        $build->setTimeout(300);
        $build->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        if (! $build->isSuccessful()) {
            $command->warn('npm run build failed. Run manually: npm run build');
        }
    }

    /** @return string[] */
    public function buildContainerArgs(InstallCommand $command): array
    {
        $args = ['php', 'artisan', 'saucebase:install', '--driver=native', '--force', '--no-logo'];

        if ($stack = $command->getSelectedStack()) {
            $args[] = $stack;
        }

        $modules = $command->getSelectedModules();
        if (! empty($modules)) {
            $args[] = '--modules='.implode(',', $modules);
        }

        foreach (['fresh', 'dev', 'all-modules'] as $opt) {
            if ($command->option($opt)) {
                $args[] = "--{$opt}";
            }
        }

        return $args;
    }

    protected function dockerComposeAvailable(): bool
    {
        return (bool) shell_exec('docker compose version 2>/dev/null');
    }

    protected function commandExists(string $name): bool
    {
        return (bool) shell_exec("which {$name} 2>/dev/null");
    }
}

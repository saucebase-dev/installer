<?php

namespace Saucebase\Installer\Environments;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;

class DockerEnvironment extends Environment
{
    protected bool $ssl = true;

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

    protected function beforePrompts(InstallCommand $command): ?int
    {
        $this->promptForSsl($command);

        if ($this->ssl && ! $this->commandExists('mkcert')) {
            $command->error('mkcert is required for SSL. Install it with: brew install mkcert');
            $command->info('Official mkcert installation instructions: https://github.com/FiloSottile/mkcert');

            return InstallCommand::FAILURE;
        }

        return null;
    }

    protected function boot(InstallCommand $command): int
    {
        $this->publishStubs($command);
        $this->generateSsl($command);

        if (! $command->ensureEnvFile()) {
            return InstallCommand::FAILURE;
        }

        $this->setDockerEnvDefaults($command);

        if (! $this->startDocker($command)) {
            return InstallCommand::FAILURE;
        }

        if (! $this->runComposerInContainer($command)) {
            return InstallCommand::FAILURE;
        }

        if (! $this->generateAppKey($command)) {
            return InstallCommand::FAILURE;
        }

        if (! $this->runMigrations($command)) {
            return InstallCommand::FAILURE;
        }

        $this->runStack($command);

        if (! $this->installModules($command)) {
            return InstallCommand::FAILURE;
        }

        $command->rewriteCrossModuleImports();
        $this->createStorageLink($command);
        $this->clearCaches($command);

        return InstallCommand::SUCCESS;
    }

    protected function promptForSsl(InstallCommand $command): void
    {
        $this->ssl = $command->option('force')
            ? true
            : confirm(
                label: 'Enable HTTPS with SSL?',
                default: true,
                hint: 'Requires mkcert. Install with: brew install mkcert',
            );
    }

    protected function publishStubs(InstallCommand $command): void
    {
        $command->info('Publishing Docker stubs...');
        Artisan::call('vendor:publish', ['--tag' => 'saucebase-docker', '--no-interaction' => true]);

        if (! $this->ssl) {
            $copied = copy(
                __DIR__.'/../../../stubs/docker/docker/nginx-no-ssl.conf',
                base_path('docker/nginx.conf'),
            );
            if (! $copied) {
                $command->warn('Failed to write nginx.conf (no-SSL). Check that Docker stubs were published first.');
            }
        }
    }

    protected function generateSsl(InstallCommand $command): void
    {
        if (! $this->ssl) {
            return;
        }

        $certFile = base_path('docker/ssl/app.pem');
        $keyFile = base_path('docker/ssl/app.key.pem');

        if (file_exists($certFile) && file_exists($keyFile)) {
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
        $command->info('Starting Docker services (this may take a few minutes while pulling images and starting containers)...');

        $restart = new Process(['docker', 'compose', 'restart']);
        $restart->setTimeout(60);
        $restart->run();

        $up = new Process(['docker', 'compose', 'up', '-d', '--wait', '--build']);
        $up->setTimeout(30 * 60); // 30 minutes — first run pulls images + builds layers
        $up->run(fn ($_type, $buffer) => $command->line(trim($buffer)));
        $command->newLine();

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

    protected function execInContainer(InstallCommand $command, array $args, int $timeout = 120): bool
    {
        $process = new Process(array_merge(['docker', 'compose', 'exec', '-T', 'app'], $args));
        $process->setTimeout($timeout);
        $process->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        return $process->isSuccessful();
    }

    protected function generateAppKey(InstallCommand $command): bool
    {
        $command->info('Generating application key...');
        $env = @file_get_contents(base_path('.env'));
        if ($env !== false && preg_match('/^APP_KEY=base64:.+$/m', $env)) {
            return true;
        }

        return $this->execInContainer($command, ['php', 'artisan', 'key:generate', '--force']);
    }

    protected function runMigrations(InstallCommand $command): bool
    {
        $fresh = $command->option('fresh');
        $command->info($fresh ? 'Running fresh migrations...' : 'Running migrations...');

        return $this->execInContainer(
            $command,
            ['php', 'artisan', $fresh ? 'migrate:fresh' : 'migrate', '--seed', '--force'],
            timeout: 300,
        );
    }

    protected function runStack(InstallCommand $command): void
    {
        if (! $stack = $command->getSelectedStack()) {
            return;
        }

        $command->info("Setting up {$stack} stack...");
        $args = ['php', 'artisan', 'saucebase:stack', $stack];

        if ($command->option('dev')) {
            $args[] = '--dev';
        }

        $this->execInContainer($command, $args);
    }

    protected function installModules(InstallCommand $command): bool
    {
        $modules = $this->resolveModules($command);

        if (empty($modules)) {
            return true;
        }

        $command->info('Installing modules...');

        $ok = $this->execInContainer(
            $command,
            ['composer', 'require', ...$modules, '--no-interaction'],
            timeout: 300,
        );

        if (! $ok) {
            $command->warn('Failed to install one or more modules — skipping patches, sync, and migrations.');

            return false;
        }

        $command->applyModulePatches($modules);
        $this->execInContainer($command, ['php', 'artisan', 'modules:sync']);
        $this->execInContainer($command, ['php', 'artisan', 'migrate', '--force'], timeout: 300);

        foreach ($modules as $package) {
            $name = Str::after($package, '/');

            if (! $command->moduleHasSeeder($name)) {
                continue;
            }

            $this->execInContainer($command, ['php', 'artisan', 'db:seed', "--module={$name}", '--force']);
        }

        return true;
    }

    protected function nextSteps(InstallCommand $command): array
    {
        $appUrl = $this->readEnvValue('APP_URL') ?? ($this->ssl ? 'https://localhost' : 'http://localhost');

        return [
            'Compile frontend assets: <fg=yellow>npm install && npm run dev</>',
            'Open your app: <fg=yellow>'.$appUrl.'</>',
            'Email testing (Mailpit): <fg=yellow>http://localhost:8025</>',
        ];
    }

    private function readEnvValue(string $key): ?string
    {
        $env = @file_get_contents(base_path('.env'));
        if ($env === false) {
            return null;
        }
        if (preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $env, $m)) {
            return trim($m[1], "\"'");
        }

        return null;
    }

    protected function createStorageLink(InstallCommand $command): void
    {
        $command->info('Creating storage link...');
        $this->execInContainer($command, ['php', 'artisan', 'storage:link']);
    }

    protected function clearCaches(InstallCommand $command): void
    {
        $command->info('Clearing caches...');
        $this->execInContainer($command, ['php', 'artisan', 'optimize:clear']);
    }

    protected function setDockerEnvDefaults(InstallCommand $command): void
    {
        $path = base_path('.env');
        $original = file_get_contents($path);

        if ($original === false) {
            return;
        }

        $modified = $this->applyDockerEnvDefaults($original, $this->ssl);

        if ($modified !== $original) {
            file_put_contents($path, $modified);
            $command->info('Docker database credentials written to .env.');
        }
    }

    protected function applyDockerEnvDefaults(string $env, bool $ssl = true): string
    {
        $slug = 'saucebase';
        if (preg_match('/^APP_SLUG=([^\s]+)/m', $env, $m)) {
            $slug = trim($m[1], "\"'");
        }

        // Docker always needs mysql, not sqlite
        if (preg_match('/^DB_CONNECTION=(.*)$/m', $env, $m) && trim($m[1]) !== 'mysql') {
            $env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=mysql', $env);
        } elseif (! preg_match('/^DB_CONNECTION=/m', $env)) {
            $env .= "\nDB_CONNECTION=mysql";
        }

        // Docker routes mail through the Mailpit container via SMTP
        if (preg_match('/^MAIL_MAILER=(.*)$/m', $env, $m) && trim($m[1]) !== 'smtp') {
            $env = preg_replace('/^MAIL_MAILER=.*$/m', 'MAIL_MAILER=smtp', $env);
        } elseif (! preg_match('/^MAIL_MAILER=/m', $env)) {
            $env .= "\nMAIL_MAILER=smtp";
        }

        // Set APP_URL to match the chosen SSL mode
        $defaultUrl = $ssl ? 'https://localhost' : 'http://localhost';
        if (preg_match('/^APP_URL=(.*)$/m', $env, $m)) {
            $url = trim($m[1], "\"'");
            if (preg_match('#^https?://localhost(:\d+)?/?$#', $url)) {
                $env = preg_replace('/^APP_URL=.*$/m', "APP_URL={$defaultUrl}", $env);
            }
        } else {
            $env .= "\nAPP_URL={$defaultUrl}";
        }

        // Set missing or blank values; respect anything the user has already configured
        $defaults = [
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $slug,
            'DB_USERNAME' => $slug,
            'DB_PASSWORD' => 'secret',
        ];

        foreach ($defaults as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)) {
                if (trim($m[1]) === '') {
                    $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', "{$key}={$value}", $env);
                }
            } else {
                $env .= "\n{$key}={$value}";
            }
        }

        return $env;
    }

    protected function dockerComposeAvailable(): bool
    {
        return (bool) shell_exec('docker compose version 2>/dev/null');
    }
}

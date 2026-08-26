<?php

namespace Saucebase\Installer\Environments;

use Illuminate\Support\Str;
use Saucebase\Installer\Console\Commands\InstallCommand;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class DockerEnvironment extends Environment
{
    /**
     * Host ports published by docker-compose.yml: env key => [compose default, label].
     * Keep in sync with stubs/docker/docker-compose.yml.
     */
    private const PORTS = [
        'APP_PORT' => [80, 'HTTP'],
        'APP_HTTPS_PORT' => [443, 'HTTPS'],
        'FORWARD_DB_PORT' => [3306, 'MySQL'],
        'FORWARD_REDIS_PORT' => [6379, 'Redis'],
        'FORWARD_MAILPIT_PORT' => [1025, 'Mailpit SMTP'],
        'FORWARD_MAILPIT_DASHBOARD_PORT' => [8025, 'Mailpit dashboard'],
    ];

    /** Where to start scanning for a replacement port; defaults to the conflicting port + 1. */
    private const ALTERNATIVE_PORT_BASES = [
        'APP_PORT' => 8080,
        'APP_HTTPS_PORT' => 8443,
    ];

    protected bool $ssl = true;

    /** @var array<string, int> Env key => replacement port, when the defaults were taken. */
    protected array $portOverrides = [];

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
            return $this->fail('Preparing .env');
        }

        // APP_HOST must land before setDockerEnvDefaults(), which builds APP_URL from it.
        $command->applyAppIdentity(native: false);
        $this->setDockerEnvDefaults($command);

        // Ports are read back from .env, so this must follow setDockerEnvDefaults().
        if (! $this->checkPorts($command)) {
            return $this->fail('Checking host ports');
        }

        // Needs the host and the final port assignments, so it follows the port check.
        $this->configureNginx($command);

        if (! $this->startDocker($command)) {
            return $this->fail('Starting Docker services');
        }

        if (! $this->runComposerInContainer($command)) {
            return $this->fail('Installing PHP dependencies');
        }

        if (! $this->generateAppKey($command)) {
            return $this->fail('Generating application key');
        }

        if (! $this->runMigrations($command)) {
            return $this->fail('Running migrations');
        }

        $this->runStack($command);

        if (! $this->installModules($command)) {
            return $this->fail('Installing modules');
        }

        $command->rewriteCrossModuleImports();
        $this->createStorageLink($command);
        $this->clearCaches($command);

        return InstallCommand::SUCCESS;
    }

    protected function promptForSsl(InstallCommand $command): void
    {
        $option = $command->option('ssl');

        $this->ssl = match (true) {
            $option !== null && $option !== '' => filter_var($option, FILTER_VALIDATE_BOOLEAN),
            (bool) $command->option('force') => true,
            default => confirm(
                label: 'Enable HTTPS with SSL?',
                default: true,
                hint: 'Requires mkcert. Install with: brew install mkcert',
            ),
        };
    }

    protected function publishStubs(InstallCommand $command): void
    {
        $command->info('Publishing Docker stubs...');

        $stubs = dirname(__DIR__, 2).'/stubs/docker';

        foreach ([
            'docker-compose.yml',
            'docker/Dockerfile',
            'docker/nginx.conf',
            'docker/php.ini',
            'docker/xdebug.ini',
        ] as $file) {
            $destination = $command->path($file);

            if (file_exists($destination)) {
                continue;
            }

            @mkdir(dirname($destination), 0755, true);

            if (! copy($stubs.'/'.$file, $destination)) {
                $command->warn("Failed to publish {$file}.");
            }
        }

        if (! $this->ssl) {
            $copied = copy(
                $stubs.'/docker/nginx-no-ssl.conf',
                $command->path('docker/nginx.conf'),
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

        $certFile = $command->path('docker/ssl/app.pem');
        $keyFile = $command->path('docker/ssl/app.key.pem');
        $host = $command->getDomain();

        // Re-installing with a different domain must not keep a cert that omits it.
        if (file_exists($certFile) && file_exists($keyFile) && $this->certCoversHost($certFile, $host)) {
            return;
        }

        $command->info('Generating SSL certificates...');
        @mkdir(dirname($certFile), 0755, true);

        (new Process(['mkcert', '-install']))->run();

        $cert = new Process([
            'mkcert',
            '-key-file', $keyFile,
            '-cert-file', $certFile,
            ...$this->certificateHosts($host),
        ]);
        $cert->run();

        if (! $cert->isSuccessful()) {
            $command->warn('SSL generation failed. Run mkcert manually if HTTPS is needed.');
        }
    }

    /** @return string[] mkcert SANs: the chosen host and its wildcard, plus localhost. */
    protected function certificateHosts(string $host): array
    {
        $hosts = ['localhost', '*.localhost', '127.0.0.1', '::1'];

        return $host === 'localhost' ? $hosts : [$host, "*.{$host}", ...$hosts];
    }

    /**
     * Whether an existing certificate already lists the host as a SAN.
     *
     * Read via the text dump rather than `-checkhost`: macOS ships LibreSSL, which
     * does not support that flag. Any failure returns false, so we regenerate.
     */
    protected function certCoversHost(string $certFile, string $host): bool
    {
        $process = new Process(['openssl', 'x509', '-in', $certFile, '-noout', '-text']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        // Anchor the end so "DNS:myapp.test" does not satisfy a request for "app.test".
        return preg_match('/DNS:'.preg_quote($host, '/').'(?=[,\s]|$)/', $process->getOutput()) === 1;
    }

    /**
     * Point the published nginx config at the chosen host and the ports actually
     * published. Both stubs ship hardcoded for localhost on 80/443.
     */
    protected function configureNginx(InstallCommand $command): void
    {
        $path = $command->path('docker/nginx.conf');
        $original = @file_get_contents($path);

        if ($original === false) {
            return;
        }

        // Read back from .env rather than $portOverrides: on a resume the ports were
        // persisted by an earlier run and nothing is overridden this time round.
        $ports = $this->resolvePorts($command);

        $modified = $this->applyNginxSettings(
            $original,
            $command->getDomain(),
            $ports['APP_PORT'],
            $ports['APP_HTTPS_PORT'],
        );

        if ($modified !== $original) {
            file_put_contents($path, $modified);
            $command->info('nginx configured for '.$command->getDomain().'.');
        }
    }

    protected function applyNginxSettings(string $conf, string $host, int $httpPort, int $httpsPort): string
    {
        // Only touch blocks that name localhost — the stub's, and the ones this method
        // wrote before (it always keeps localhost as a secondary name). A vhost the user
        // added themselves has no localhost and is left completely alone. Matching any
        // existing name, not just the stub's, is what lets a re-install with a different
        // domain replace the old one. The custom name goes first: the HTTP block
        // redirects using $server_name, which resolves to the primary name.
        $names = $host === 'localhost' ? 'localhost' : $host.' localhost';
        $conf = preg_replace(
            '/^(\s*)server_name\s+([^;]*\blocalhost\b[^;]*);/m',
            '$1server_name '.$names.';',
            $conf,
        );

        // $server_name carries no port, so a redirect would land on 443 even when
        // HTTPS was remapped. Matches an existing port so re-runs re-target cleanly.
        $conf = preg_replace_callback(
            '#https://\$server_name(:\d+)?\$request_uri#',
            fn () => 'https://$server_name'.($httpsPort !== 443 ? ':'.$httpsPort : '').'$request_uri',
            $conf,
        );

        // Laravel builds URLs from what PHP is told the port is; without this a stack
        // moved off 443 by the port check would still advertise 443.
        return preg_replace_callback(
            '/^(\s*fastcgi_param\s+SERVER_PORT\s+)(\d+)(;)/m',
            fn (array $m) => $m[1].($m[2] === '443' ? $httpsPort : $httpPort).$m[3],
            $conf,
        );
    }

    /**
     * Verify every host port docker-compose.yml publishes is free before starting,
     * so a clash surfaces as a choice rather than as a raw daemon bind error.
     */
    protected function checkPorts(InstallCommand $command): bool
    {
        $wanted = $this->resolvePorts($command);
        $inUse = array_filter($wanted, fn (int $port) => $this->portInUse($port));

        if (empty($inUse)) {
            return true;
        }

        $owners = $this->dockerPortOwners();
        $ours = $this->ownContainers($command);

        // A resumed install finds its own containers already holding these ports.
        // Remapping them would move the whole app on every retry.
        $conflicts = array_filter(
            $inUse,
            fn (int $port) => ! in_array($owners[$port]['container'] ?? '', $ours, true),
        );

        if (empty($conflicts)) {
            return true;
        }

        $command->warn('Some host ports this app needs are already in use:');
        foreach ($conflicts as $key => $port) {
            $command->line(sprintf('  %d (%s) — %s', $port, self::PORTS[$key][1], $this->describeOwner($owners[$port] ?? null)));
        }

        $targets = $this->stoppableTargets($conflicts, $owners);

        $options = [];
        if ($targets !== []) {
            $options['stop'] = 'Stop '.$this->describeTargets($targets).' and continue';
        }
        $options['ports'] = 'Use alternative free ports for this app';
        $options['abort'] = 'Abort the install';

        // Alternative ports is the safe default: it is the only choice that resolves
        // the clash without touching containers someone else may still be using.
        $choice = $command->option('force') ? 'ports' : select(
            label: 'How would you like to continue?',
            options: $options,
            default: 'ports',
        );

        return match ($choice) {
            'stop' => $this->stopConflicting($command, $targets, $conflicts),
            'ports' => $this->useAlternativePorts($command, $wanted, $conflicts),
            default => $this->abortForPorts($command),
        };
    }

    /** @return array<string, int> Env key => the port that will be published. */
    protected function resolvePorts(InstallCommand $command): array
    {
        $ports = [];

        foreach (self::PORTS as $key => [$default]) {
            $value = $this->readEnvValue($command, $key);
            $ports[$key] = is_numeric($value) ? (int) $value : $default;
        }

        return $ports;
    }

    protected function portInUse(int $port): bool
    {
        // ponytail: connect-probe — misses a listener bound only to a non-loopback
        // interface. A bind-probe matches Docker more closely but needs root for
        // 80/443, so it would false-positive far more often. Swap if this ever bites.
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** @return string[] Names of the containers belonging to this app's own Compose project. */
    protected function ownContainers(InstallCommand $command): array
    {
        $process = new Process(['docker', 'compose', 'ps', '--format', '{{.Name}}'], $command->targetPath());
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $process->getOutput()) ?: [])));
    }

    /** @return array<int, array{container: string, project: string|null}> Host port => owner. */
    protected function dockerPortOwners(): array
    {
        $process = new Process([
            'docker', 'ps', '--format', '{{.Ports}}|{{.Names}}|{{.Label "com.docker.compose.project"}}',
        ]);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() ? $this->parseDockerPortOwners($process->getOutput()) : [];
    }

    /** @return array<int, array{container: string, project: string|null}> */
    protected function parseDockerPortOwners(string $psOutput): array
    {
        $owners = [];

        foreach (preg_split('/\R/', trim($psOutput)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$ports, $container, $project] = array_pad(explode('|', $line, 3), 3, '');

            // Only published bindings carry a "host:port->" prefix. A bare "9000/tcp"
            // is merely exposed to the container network and binds nothing on the host.
            preg_match_all('/:(\d+)->/', $ports, $matches);

            foreach ($matches[1] as $port) {
                $owners[(int) $port] ??= [
                    'container' => $container,
                    'project' => $project !== '' ? $project : null,
                ];
            }
        }

        return $owners;
    }

    /** @param  array{container: string, project: string|null}|null  $owner */
    protected function describeOwner(?array $owner): string
    {
        if ($owner === null) {
            return 'held by another process on this machine (not Docker, so the installer cannot stop it)';
        }

        return $owner['project'] !== null
            ? sprintf('held by Docker project "%s" (container %s)', $owner['project'], $owner['container'])
            : sprintf('held by Docker container "%s"', $owner['container']);
    }

    /**
     * Docker stacks whose shutdown would clear *every* conflict. Stopping a subset
     * would leave the install blocked anyway, so it is not offered.
     *
     * @param  array<string, int>  $conflicts
     * @param  array<int, array{container: string, project: string|null}>  $owners
     * @return array<int, array{container: string, project: string|null}>
     */
    protected function stoppableTargets(array $conflicts, array $owners): array
    {
        $targets = [];

        foreach ($conflicts as $port) {
            if (! isset($owners[$port])) {
                return [];
            }

            $targets[$owners[$port]['project'] ?? $owners[$port]['container']] = $owners[$port];
        }

        return array_values($targets);
    }

    /** @param  array<int, array{container: string, project: string|null}>  $targets */
    protected function describeTargets(array $targets): string
    {
        return implode(', ', array_map(
            fn (array $target) => $target['project'] !== null
                ? sprintf('Docker project "%s"', $target['project'])
                : sprintf('Docker container "%s"', $target['container']),
            $targets,
        ));
    }

    /**
     * @param  array<int, array{container: string, project: string|null}>  $targets
     * @param  array<string, int>  $conflicts
     */
    protected function stopConflicting(InstallCommand $command, array $targets, array $conflicts): bool
    {
        // Stopping someone else's containers is destructive — never without an explicit yes.
        $confirmed = confirm(
            label: 'Stop '.$this->describeTargets($targets).'?',
            default: false,
            hint: 'This shuts down containers that another app may still be using.',
        );

        if (! $confirmed) {
            $command->warn('Nothing was stopped.');

            return $this->abortForPorts($command);
        }

        foreach ($targets as $target) {
            $command->info('Stopping '.($target['project'] ?? $target['container']).'...');

            $process = $target['project'] !== null
                ? new Process(['docker', 'compose', '-p', $target['project'], 'stop'])
                : new Process(['docker', 'stop', $target['container']]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                $command->error('Failed to stop '.($target['project'] ?? $target['container']).': '.$process->getErrorOutput());
            }
        }

        $remaining = array_filter($conflicts, fn (int $port) => $this->portInUse($port));

        if ($remaining !== []) {
            $command->error('Still in use after stopping: '.implode(', ', $remaining));

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, int>  $wanted
     * @param  array<string, int>  $conflicts
     */
    protected function useAlternativePorts(InstallCommand $command, array $wanted, array $conflicts): bool
    {
        // Seed with the ports we are already keeping so two services cannot collide.
        $taken = array_values(array_diff_key($wanted, $conflicts));

        foreach ($conflicts as $key => $port) {
            $free = $this->freePort(self::ALTERNATIVE_PORT_BASES[$key] ?? $port + 1, $taken);

            if ($free === 0) {
                $command->error(sprintf('Could not find a free port to replace %d (%s).', $port, self::PORTS[$key][1]));

                return false;
            }

            $taken[] = $free;
            $this->portOverrides[$key] = $free;
            $command->line(sprintf('  %s: %d → %d', self::PORTS[$key][1], $port, $free));
        }

        // Re-run the single .env writer, now carrying the overrides.
        $this->setDockerEnvDefaults($command);

        return true;
    }

    /** @param  int[]  $taken */
    protected function freePort(int $base, array $taken = []): int
    {
        for ($port = $base; $port < $base + 100; $port++) {
            if (! in_array($port, $taken, true) && ! $this->portInUse($port)) {
                return $port;
            }
        }

        return 0;
    }

    protected function abortForPorts(InstallCommand $command): bool
    {
        $command->error('Aborted: the ports Docker needs are in use.');
        $command->line('Free them (or stop the other app), then re-run the install.');

        return false;
    }

    protected function startDocker(InstallCommand $command): bool
    {
        $command->info('Starting Docker services (this may take a few minutes while pulling images and starting containers)...');

        $restart = new Process(['docker', 'compose', 'restart'], $command->targetPath());
        $restart->setTimeout(60);
        $restart->run();

        $up = new Process(['docker', 'compose', 'up', '-d', '--wait', '--build'], $command->targetPath());
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
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'composer', 'install'], $command->targetPath());
        $process->setTimeout(300);
        $process->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        if (! $process->isSuccessful()) {
            $command->error('composer install failed inside container.');
        }

        return $process->isSuccessful();
    }

    protected function execInContainer(InstallCommand $command, array $args, int $timeout = 120): bool
    {
        $process = new Process(array_merge(['docker', 'compose', 'exec', '-T', 'app'], $args), $command->targetPath());
        $process->setTimeout($timeout);
        $process->run(fn ($_type, $buffer) => $command->line(trim($buffer)));

        return $process->isSuccessful();
    }

    protected function generateAppKey(InstallCommand $command): bool
    {
        $command->info('Generating application key...');

        if ($command->envHasAppKey()) {
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

        // File operations on the host — the app directory is volume-mounted,
        // and the stack command no longer exists inside the container.
        $command->runStack();
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
        $appUrl = $this->readEnvValue($command, 'APP_URL') ?? ($this->ssl ? 'https://localhost' : 'http://localhost');
        $mailpit = $this->readEnvValue($command, 'FORWARD_MAILPIT_DASHBOARD_PORT') ?? '8025';

        return [
            'Compile frontend assets: `npm install && npm run dev`',
            'Open your app: `'.$appUrl.'`',
            'Email testing (Mailpit): `http://localhost:'.$mailpit.'`',
        ];
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
        $path = $command->path('.env');
        $original = file_get_contents($path);

        if ($original === false) {
            return;
        }

        $modified = $this->applyDockerEnvDefaults($original, $this->ssl, $this->portOverrides);

        if ($modified !== $original) {
            file_put_contents($path, $modified);
            $command->info('Docker settings written to .env.');
        }
    }

    /** @param  array<string, int>  $ports  Env key => port, forced over any existing value. */
    protected function applyDockerEnvDefaults(string $env, bool $ssl = true, array $ports = []): string
    {
        $slug = 'saucebase';
        if (preg_match('/^APP_SLUG=([^\s]+)/m', $env, $m)) {
            $slug = trim($m[1], "\"'");
        }

        // The identity pass (InstallCommand::applyAppIdentity) always runs first, so
        // APP_HOST carries the domain that was chosen for this app.
        $host = 'localhost';
        if (preg_match('/^APP_HOST=([^\s]+)/m', $env, $m)) {
            $host = trim($m[1], "\"'");
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

        // Set APP_URL to match the chosen host and SSL mode, carrying the published
        // port whenever it is not the scheme's default one.
        $portKey = $ssl ? 'APP_HTTPS_PORT' : 'APP_PORT';
        $appPort = $ports[$portKey] ?? null;

        // No override this run: fall back to a port an earlier run already persisted,
        // otherwise a resume would silently drop :8443 from the URL.
        if ($appPort === null && preg_match('/^'.$portKey.'=(\d+)/m', $env, $m)) {
            $appPort = (int) $m[1];
        }

        $suffix = ($appPort !== null && $appPort !== ($ssl ? 443 : 80)) ? ':'.$appPort : '';
        $defaultUrl = ($ssl ? 'https' : 'http').'://'.$host.$suffix;
        if (preg_match('/^APP_URL=(.*)$/m', $env, $m)) {
            $url = trim($m[1], "\"'");
            // Correct a URL for localhost or for the chosen host (scheme and port may
            // have changed); leave a genuinely custom one alone.
            if (preg_match('#^https?://(localhost|'.preg_quote($host, '#').')(:\d+)?/?$#', $url)) {
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

        // Port overrides are forced, not defaulted: the value already there is the
        // one that clashed.
        foreach ($ports as $key => $port) {
            $env = InstallCommand::setEnvLine($env, $key, (string) $port);
        }

        return $env;
    }

    protected function resumeOptions(): array
    {
        return array_merge(parent::resumeOptions(), ['--ssl' => $this->ssl ? 'yes' : 'no']);
    }

    protected function dockerComposeAvailable(): bool
    {
        return (bool) shell_exec('docker compose version 2>/dev/null');
    }
}

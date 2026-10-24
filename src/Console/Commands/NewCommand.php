<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Saucebase\Installer\Console\Application;
use Saucebase\Installer\Console\Commands\Concerns\DisplaysBanner;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\HerdEnvironment;
use Saucebase\Installer\ModuleRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use DisplaysBanner;

    protected $signature = 'new
                            {name? : The name of the new Saucebase application}
                            {--driver= : Environment driver (docker, native, herd) — prompted if omitted}
                            {--stack= : Frontend stack (vue or react) — prompted if omitted}
                            {--modules= : Comma-separated list of modules to enable, or "none"}
                            {--all-modules : Enable and migrate all available modules without prompting}
                            {--ssl= : Enable HTTPS with mkcert for docker (yes/no) — prompted if omitted}
                            {--domain= : Hostname the app is served on (e.g. myapp.test) — prompted if omitted}
                            {--app-name= : What the app is called (defaults to its directory name), prompted if omitted}
                            {--using= : A Saucebase template to start from, instead of a plain Laravel application}
                            {--dev : Dev environment}
                            {--demo : Load demo content and demo users}
                            {--fresh : Run migrate:fresh instead of migrate (destructive)}
                            {--force : Overwrite an existing directory and skip confirmations}';

    protected $description = 'Create a new Saucebase application';

    public function handle(): int
    {
        $this->displayWelcome();

        if (($blocked = $this->checkForUpdates()) !== null) {
            return $blocked;
        }

        [$name, $appName] = $this->names($this->argument('name'));

        $driver = $this->resolveDriver($name);

        $missing = $driver->missingPrerequisites();
        if (! empty($missing)) {
            foreach ($missing as $message) {
                $this->error($message);
            }
            $this->displayPrerequisiteHints($driver);

            return self::FAILURE;
        }

        $ssl = $this->ssl($driver);

        if ($ssl === true && ! $driver->hasCommand('mkcert')) {
            $this->error('mkcert is required for SSL. Install it with: brew install mkcert');
            $this->info('Official mkcert installation instructions: https://github.com/FiloSottile/mkcert');

            return self::FAILURE;
        }

        $domain = $this->resolveDomain($name, $driver);

        // A template brings its own framework, which the install reads once the files are there.
        $stack = $this->option('stack') ?: ($this->option('using') ? null : select(
            label: 'Which frontend stack would you like to use?',
            options: ['vue' => 'Vue', 'react' => 'React'],
            default: 'vue',
        ));

        $modules = $this->resolveModulesUpfront($stack);
        $demo = $this->wantsDemo($modules);

        if ($this->createProject($name) !== 0) {
            $this->error('Project creation failed.');

            return self::FAILURE;
        }

        $this->line('  Setup continues below - see the final summary for the real next steps.');

        $arguments = $this->installArguments($name, $driver, $stack, $ssl, $modules, $domain, $appName, $demo);
        $result = $this->call('install', $arguments);

        if ($result === self::SUCCESS) {
            $this->saveDriverPreference($driver->name());
            $this->newLine();
            $this->line("  Your application is ready in <fg=yellow>./{$name}</>");
        }

        return $result;
    }

    /**
     * The app's name and the folder it goes in. Asked name first: the folder is its slug.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function names(?string $folder): array
    {
        if ($folder !== null) {
            // The directory name is the default: a brand often differs from the folder it lives in.
            $appName = $this->option('app-name') ?: ($this->input->isInteractive()
                ? text(label: 'What should the app be called?', default: Str::headline($folder), required: true)
                : null);

            return [$folder, $appName];
        }

        $appName = $this->option('app-name') ?: text(
            label: 'What should the app be called?',
            placeholder: 'E.g. Acme Shop',
            required: 'The app name is required.',
        );

        $folder = text(label: 'Which folder should it go in?', default: Str::slug($appName), required: true);

        return [$folder, $appName];
    }

    protected function resolveDriver(string $name): Environment
    {
        $driver = $this->option('driver') ?? select(
            label: 'How would you like to run Saucebase?',
            // Offered only where it can run.
            options: $this->driverOptions($name, herd: (new HerdEnvironment)->hasCommand('herd')),
            default: $this->savedDriverPreference() ?? 'docker',
        );

        return Environment::make($driver);
    }

    /** @return array<string, string> */
    protected function driverOptions(string $name, bool $herd): array
    {
        return [
            'docker' => 'Docker - recommended for real projects: MySQL, Redis, Mailpit, HTTPS',
            'native' => 'Native PHP - minimal setup, ideal for exploring',
            ...($herd ? ['herd' => 'Laravel Herd - served at https://'.Str::slug($name).'.test'] : []),
        ];
    }

    /** Docker asks; Herd secures unless told not to; native serves plain HTTP. */
    protected function ssl(Environment $driver): ?bool
    {
        return match ($driver->name()) {
            'docker' => $this->resolveSsl($driver),
            'herd' => $this->option('ssl') !== 'no',
            default => null,
        };
    }

    protected function resolveSsl(Environment $driver): bool
    {
        $option = $this->option('ssl');

        return match (true) {
            $option !== null && $option !== '' => filter_var($option, FILTER_VALIDATE_BOOLEAN),
            (bool) $this->option('force') => true,
            default => confirm(
                label: 'Enable HTTPS with SSL?',
                default: true,
                hint: 'Requires mkcert. Install with: brew install mkcert',
            ),
        };
    }

    /**
     * The hostname the app will be served on. Collected upfront (like ssl and stack)
     * because the SSL certificate and nginx config are both built from it.
     */
    protected function resolveDomain(string $name, Environment $driver): string
    {
        $option = $this->option('domain');

        $answer = match (true) {
            is_string($option) && $option !== '' => $option,
            // Herd serves every site at its own .test name.
            $driver->name() === 'herd' => Str::slug($name).'.test',
            (bool) $this->option('force') => 'localhost',
            default => text(
                label: 'Which hostname will you use for this app?',
                default: 'localhost',
                hint: 'Use localhost, or a custom domain such as '.strtolower($name).'.test',
                validate: fn (string $value) => InstallCommand::normalizeDomain($value) === null
                    ? 'Enter a bare hostname, e.g. myapp.test'
                    : null,
            ),
        };

        return InstallCommand::normalizeDomain($answer) ?? 'localhost';
    }

    /**
     * Prompt for modules upfront so the install can run unattended.
     * Returns null when module selection is already driven by options.
     *
     * @return string[]|null
     */
    protected function resolveModulesUpfront(?string $stack): ?array
    {
        if ($stack === null || $this->option('all-modules') || $this->option('dev')
            || $this->option('modules') !== null) {
            return null;
        }

        $registry = $this->registry();
        $available = $registry->filterByFramework($registry->available(), $stack);

        return empty($available) ? [] : $registry->promptSelection($available);
    }

    /**
     * Demo content comes from the modules, so there is nothing to offer without them.
     * Declined by default: a real app would have to delete it, demo users included.
     *
     * @param  string[]|null  $modules  Null when the install decides which modules come
     */
    protected function wantsDemo(?array $modules): bool
    {
        if ($this->option('demo')) {
            return true;
        }

        if ($modules === [] || $this->option('modules') === 'none') {
            return false;
        }

        return confirm(
            label: 'Load demo content?',
            default: false,
            hint: 'Sample content and users to try Saucebase out',
        );
    }

    protected function registry(): ModuleRegistry
    {
        return new ModuleRegistry;
    }

    protected function createProject(string $name): int
    {
        $laravel = new LaravelNewCommand;
        $laravel->setApplication($this->getApplication());

        // All prompts were collected upfront; creating the project runs unattended.
        $input = new ArrayInput($this->projectArguments($name));
        $input->setInteractive(false);

        return $laravel->run($input, $this->output);
    }

    /**
     * What laravel/installer is asked for: a plain Laravel application, unless a
     * template was named. A contributor (--dev) starts from the Saucebase skeleton.
     *
     * @return array<string, mixed>
     */
    protected function projectArguments(string $name): array
    {
        $arguments = [
            'name' => $name,
            '--phpunit' => true,
            '--no-node' => true,
            '--no-boost' => true,
            // Initialise git so module patches (git apply) and --dev worktree
            // tracking operate on a real repository.
            '--git' => true,
        ];

        if ($using = $this->option('using') ?: ($this->option('dev') ? 'saucebase/saucebase' : null)) {
            $arguments['--using'] = $using;
        }

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        return $arguments;
    }

    /**
     * @param  string[]|null  $modules
     * @return array<string, mixed>
     */
    protected function installArguments(
        string $name,
        Environment $driver,
        ?string $stack,
        ?bool $ssl,
        ?array $modules,
        string $domain = 'localhost',
        ?string $appName = null,
        bool $demo = false,
    ): array {
        $arguments = array_filter(['stack' => $stack]) + [
            '--path' => getcwd().'/'.$name,
            '--driver' => $driver->name(),
            '--domain' => $domain,
            '--no-logo' => true,
        ];

        if ($ssl !== null) {
            $arguments['--ssl'] = $ssl ? 'yes' : 'no';
        }

        if ($appName !== null) {
            $arguments['--app-name'] = $appName;
        }

        if ($modules !== null) {
            $arguments['--modules'] = empty($modules) ? 'none' : implode(',', $modules);
        } elseif ($this->option('modules') !== null) {
            $arguments['--modules'] = $this->option('modules');
        }

        if ($demo) {
            $arguments['--demo'] = true;
        }

        foreach (['all-modules', 'dev', 'fresh', 'force'] as $flag) {
            if ($this->option($flag)) {
                $arguments['--'.$flag] = true;
            }
        }

        return $arguments;
    }

    protected function displayPrerequisiteHints(Environment $driver): void
    {
        $this->newLine();

        if ($driver->name() === 'docker') {
            $this->line('Install Docker Desktop: <fg=cyan>https://www.docker.com/products/docker-desktop/</>');

            return;
        }

        $this->line('Install PHP, Composer and the required tooling with one command via <fg=cyan>https://php.new</>:');
        $this->line(match (PHP_OS_FAMILY) {
            'Windows' => '  <fg=yellow>Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString(\'https://php.new/install/windows\'))</>',
            'Darwin' => '  <fg=yellow>/bin/bash -c "$(curl -fsSL https://php.new/install/mac)"</>',
            default => '  <fg=yellow>/bin/bash -c "$(curl -fsSL https://php.new/install/linux)"</>',
        });
    }

    /**
     * Create apps with the newest installer: an older one updates itself, then runs this
     * command again on the new code. Declined or failed, it warns, or stops when a major
     * version behind.
     *
     * @return int|null An exit code to stop with, null to carry on
     */
    protected function checkForUpdates(): ?int
    {
        $current = $this->currentVersion();

        // Set for the run after an update: never update twice, whatever Packagist says.
        if ($current === '' || str_contains($current, 'dev') || getenv('SAUCEBASE_UPDATED') === '1') {
            return null;
        }

        $latest = $this->latestVersion();

        if ($latest === null || version_compare($current, $latest, '>=')) {
            return null;
        }

        $wantsUpdate = ! $this->input->isInteractive() || confirm(
            label: "A newer Saucebase installer is available ({$current} → {$latest}). Update it first?",
            default: true,
        );

        if ($wantsUpdate && $this->updateInstaller($latest)) {
            $this->info("Updated the installer to {$latest}. Continuing with it...");

            return $this->runAgain();
        }

        if ((int) explode('.', $latest)[0] > (int) explode('.', $current)[0]) {
            $this->error("Your installer ({$current}) is a major version behind the latest release ({$latest}) and may not support current Saucebase applications.");
            $this->line("Update it first: <fg=yellow>composer global require saucebase/installer:^{$latest}</>");

            return self::FAILURE;
        }

        $this->warn("Carrying on with installer {$current}. Update later with: composer global require saucebase/installer:^{$latest}");

        return null;
    }

    protected function currentVersion(): string
    {
        return ltrim(Application::version(), 'v');
    }

    /** Requires the new version itself: `update` keeps the global constraint, so it never crosses a major. */
    protected function updateInstaller(string $latest): bool
    {
        return Process::timeout(300)
            ->run(['composer', 'global', 'require', "saucebase/installer:^{$latest}", '--with-all-dependencies', '--no-interaction'])
            ->successful();
    }

    /** The same command on the updated code, attached to this terminal so its prompts still work. */
    protected function runAgain(): int
    {
        return Process::forever()
            ->env(['SAUCEBASE_UPDATED' => '1'])
            ->tty(SymfonyProcess::isTtySupported())
            ->run([PHP_BINARY, ...$this->rerunArguments()], fn ($_type, $buffer) => $this->output->write($buffer))
            ->exitCode() ?? self::FAILURE;
    }

    /** @return string[] The script and arguments this process was started with. */
    protected function rerunArguments(): array
    {
        return $_SERVER['argv'] ?? [];
    }

    /**
     * The newest published release of the installer, or null when offline.
     *
     * Packagist's p2 endpoint lists tagged releases only (dev branches live in a
     * separate ~dev.json), so index 0 is always the latest stable.
     */
    protected function latestVersion(): ?string
    {
        $package = 'saucebase/installer';

        try {
            $response = Http::timeout(2)->get("https://repo.packagist.org/p2/{$package}.json");

            if (! $response->ok()) {
                return null;
            }

            $version = $response->json("packages.{$package}.0.version");

            return is_string($version) ? ltrim($version, 'v') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function configFilePath(): string
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: sys_get_temp_dir());

        return $home.'/.config/saucebase/config.json';
    }

    protected function savedDriverPreference(): ?string
    {
        $config = @json_decode((string) @file_get_contents($this->configFilePath()), true);

        $driver = $config['driver'] ?? null;

        return in_array($driver, ['docker', 'native'], true) ? $driver : null;
    }

    protected function saveDriverPreference(string $driver): void
    {
        $path = $this->configFilePath();

        @mkdir(dirname($path), 0755, true);

        $config = @json_decode((string) @file_get_contents($path), true) ?: [];
        $config['driver'] = $driver;

        @file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT).PHP_EOL);
    }
}

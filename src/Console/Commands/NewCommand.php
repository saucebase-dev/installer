<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Saucebase\Installer\Console\Application;
use Saucebase\Installer\Console\Commands\Concerns\DisplaysBanner;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\ModuleRegistry;
use Symfony\Component\Console\Input\ArrayInput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use DisplaysBanner;

    /**
     * Constraint used only when Packagist can't be reached to resolve the newest
     * skeleton release. Must stay numeric — `@stable` does not override the
     * `--stability=dev` that laravel/installer forces, so anything non-numeric
     * silently installs dev-main. Bump this when the skeleton changes major.
     */
    private const SKELETON_FALLBACK = '^2.0';

    protected $signature = 'new
                            {name? : The name of the new Saucebase application}
                            {--driver= : Environment driver (docker, native) — prompted if omitted}
                            {--stack= : Frontend stack (vue or react) — prompted if omitted}
                            {--modules= : Comma-separated list of modules to enable, or "none"}
                            {--all-modules : Enable and migrate all available modules without prompting}
                            {--ssl= : Enable HTTPS with mkcert for docker (yes/no) — prompted if omitted}
                            {--using= : The skeleton package to install (defaults to saucebase/saucebase)}
                            {--dev : Dev environment}
                            {--fresh : Run migrate:fresh instead of migrate (destructive)}
                            {--force : Overwrite an existing directory and skip confirmations}';

    protected $description = 'Create a new Saucebase application';

    public function handle(): int
    {
        $this->displayWelcome();

        if (($blocked = $this->checkForUpdates()) !== null) {
            return $blocked;
        }

        $name = $this->argument('name') ?? text(
            label: 'What is the name of your project?',
            placeholder: 'E.g. my-app',
            required: 'The project name is required.',
        );

        $driver = $this->resolveDriver();

        $missing = $driver->missingPrerequisites();
        if (! empty($missing)) {
            foreach ($missing as $message) {
                $this->error($message);
            }
            $this->displayPrerequisiteHints($driver);

            return self::FAILURE;
        }

        $ssl = $driver->name() === 'docker' ? $this->resolveSsl($driver) : null;

        if ($ssl === true && ! $driver->hasCommand('mkcert')) {
            $this->error('mkcert is required for SSL. Install it with: brew install mkcert');
            $this->info('Official mkcert installation instructions: https://github.com/FiloSottile/mkcert');

            return self::FAILURE;
        }

        $stack = $this->option('stack') ?: select(
            label: 'Which frontend stack would you like to use?',
            options: ['vue' => 'Vue', 'react' => 'React'],
            default: 'vue',
        );

        $modules = $this->resolveModulesUpfront($stack);

        if ($this->createProject($name) !== 0) {
            $this->error('Project creation failed.');

            return self::FAILURE;
        }

        $result = $this->call('install', $this->installArguments($name, $driver, $stack, $ssl, $modules));

        if ($result === self::SUCCESS) {
            $this->saveDriverPreference($driver->name());
            $this->newLine();
            $this->line("  Your application is ready in <fg=yellow>./{$name}</>");
        }

        return $result;
    }

    protected function resolveDriver(): Environment
    {
        $name = $this->option('driver') ?? select(
            label: 'How would you like to run Saucebase?',
            options: [
                'docker' => 'Docker - recommended for real projects: MySQL, Redis, Mailpit, HTTPS',
                'native' => 'Native PHP - minimal setup, ideal for exploring',
            ],
            default: $this->savedDriverPreference() ?? 'docker',
        );

        return Environment::make($name);
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
     * Prompt for modules upfront so the install can run unattended.
     * Returns null when module selection is already driven by options.
     *
     * @return string[]|null
     */
    protected function resolveModulesUpfront(string $stack): ?array
    {
        if ($this->option('all-modules') || $this->option('modules') !== null || $this->option('dev')) {
            return null;
        }

        $registry = $this->registry();
        $available = $registry->filterByFramework($registry->available(), $stack);

        return empty($available) ? [] : $registry->promptSelection($available);
    }

    protected function registry(): ModuleRegistry
    {
        return new ModuleRegistry;
    }

    protected function createProject(string $name): int
    {
        $laravel = new LaravelNewCommand;
        $laravel->setApplication($this->getApplication());

        $arguments = [
            'name' => $name,
            '--using' => $this->skeletonPackage(),
            '--phpunit' => true,
            '--no-node' => true,
            '--no-boost' => true,
            // Initialise git so module patches (git apply) and --dev worktree
            // tracking operate on a real repository.
            '--git' => true,
        ];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        // All prompts were collected upfront; the skeleton install runs unattended.
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $laravel->run($input, $this->output);
    }

    /**
     * The skeleton package (with version constraint) to hand to laravel/installer.
     *
     * laravel/installer always appends `--stability=dev` to its create-project
     * call, which would otherwise pull the skeleton's default (dev) branch. We
     * pin to the latest stable of the installer's own major version — the
     * installer and skeleton share a major by design — so real installs get a
     * stable release. Contributors (--dev) and source checkouts get the dev branch.
     */
    protected function skeletonPackage(): string
    {
        if ($using = $this->option('using')) {
            return $using;
        }

        $package = 'saucebase/saucebase';

        if ($this->option('dev')) {
            return $package;
        }

        // laravel/installer hardcodes --stability=dev when it shells out to
        // `composer create-project`, so a bare package name resolves to dev-main.
        // Verified: `@stable` does NOT override that flag — only a numeric
        // constraint excludes the dev branch. So resolve the newest published
        // release and pin it, rather than coupling to the installer's own major.
        $latest = $this->latestVersion($package);

        return "{$package}:".($latest ?? self::SKELETON_FALLBACK);
    }

    /**
     * @param  string[]|null  $modules
     * @return array<string, mixed>
     */
    protected function installArguments(string $name, Environment $driver, string $stack, ?bool $ssl, ?array $modules): array
    {
        $arguments = [
            'stack' => $stack,
            '--path' => getcwd().'/'.$name,
            '--driver' => $driver->name(),
            '--no-logo' => true,
        ];

        if ($ssl !== null) {
            $arguments['--ssl'] = $ssl ? 'yes' : 'no';
        }

        if ($modules !== null) {
            $arguments['--modules'] = empty($modules) ? 'none' : implode(',', $modules);
        } elseif ($this->option('modules') !== null) {
            $arguments['--modules'] = $this->option('modules');
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
     * Warn when a newer installer exists; block when a major version behind.
     */
    protected function checkForUpdates(): ?int
    {
        $current = ltrim(Application::version(), 'v');

        if ($current === '' || str_contains($current, 'dev')) {
            return null;
        }

        $latest = $this->latestVersion();

        if ($latest === null || version_compare($current, $latest, '>=')) {
            return null;
        }

        if ((int) explode('.', $latest)[0] > (int) explode('.', $current)[0]) {
            $this->error("Your installer ({$current}) is a major version behind the latest release ({$latest}) and may not support current Saucebase applications.");
            $this->line('Update it first: <fg=yellow>composer global update saucebase/installer</>');

            return self::FAILURE;
        }

        $this->components->warn("A new version of the Saucebase installer is available ({$current} → {$latest}). Update with: composer global update saucebase/installer");

        return null;
    }

    /**
     * The newest published release of a package, or null when offline.
     *
     * Packagist's p2 endpoint lists tagged releases only (dev branches live in a
     * separate ~dev.json), so index 0 is always the latest stable.
     */
    protected function latestVersion(string $package = 'saucebase/installer'): ?string
    {
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

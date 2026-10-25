<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\Element;
use Laravel\Prompts\Elements\ElementContract;
use Saucebase\Installer\Console\Commands\Concerns\DisplaysBanner;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\HerdEnvironment;
use Saucebase\Installer\ModuleRegistry;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use DisplaysBanner;

    protected $signature = 'install
                            {stack? : The frontend stack to install (vue or react)}
                            {--path= : The Saucebase application directory (defaults to the current directory)}
                            {--driver= : Environment driver (docker, native, herd) — prompted if omitted}
                            {--ssl= : Enable HTTPS with mkcert for docker (yes/no) — prompted if omitted}
                            {--domain= : Hostname the app is served on (e.g. myapp.test) — prompted if omitted}
                            {--app-name= : What the app is called (defaults to its directory name)}
                            {--demo : Load demo content and demo users}
                            {--fresh : Run migrate:fresh instead of migrate (destructive)}
                            {--all-modules : Enable and migrate all available modules without prompting}
                            {--modules= : Comma-separated list of modules to enable (e.g. Auth,Settings), or "none"}
                            {--dev : Dev environment}
                            {--force : Skip confirmations}
                            {--no-logo : Suppress the welcome banner}';

    protected $description = 'Install and configure an existing Saucebase application';

    protected ?string $selectedStack = null;

    protected ?string $domain = null;

    /** @var string[] */
    protected array $selectedModules = [];

    protected ?string $resolvedTargetPath = null;

    protected ?ModuleRegistry $registry = null;

    public function handle(): int
    {
        if (! $this->option('no-logo')) {
            $this->displayWelcome();
        }

        $this->captureStack();

        if ($this->isCI()) {
            return $this->handleCIInstallation();
        }

        $this->captureDomain();

        $driver = $this->resolveDriver();

        $missing = $driver->missingPrerequisites();
        if (! empty($missing)) {
            foreach ($missing as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        return $driver->run($this);
    }

    public function targetPath(): string
    {
        if ($this->resolvedTargetPath === null) {
            $this->resolvedTargetPath = rtrim($this->optionOrNull('path') ?: getcwd(), '/');
        }

        return $this->resolvedTargetPath;
    }

    /** Read an option, tolerating a command instantiated outside the console app (no input bound). */
    public function optionOrNull(string $key): string|array|bool|null
    {
        try {
            return $this->option($key);
        } catch (\Throwable) {
            return null;
        }
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->targetPath() : $this->targetPath().'/'.$relative;
    }

    /**
     * Run an artisan command inside the target application via a subprocess.
     *
     * @param  string[]  $args
     */
    public function runArtisan(array $args, int $timeout = 120): bool
    {
        return Process::path($this->targetPath())
            ->timeout($timeout)
            ->run([PHP_BINARY, $this->path('artisan'), ...$args])
            ->successful();
    }

    public function getSelectedStack(): ?string
    {
        return $this->selectedStack;
    }

    /** @return string[] */
    public function getSelectedModules(): array
    {
        return $this->selectedModules;
    }

    protected function resolveDriver(): Environment
    {
        $name = $this->option('driver') ?? select(
            label: 'How would you like to run Saucebase?',
            options: [
                'docker' => 'Docker - recommended for real projects: MySQL, Redis, Mailpit, HTTPS',
                'native' => 'Native PHP - minimal setup, ideal for exploring',
                // Offered only where it can run.
                ...((new HerdEnvironment)->hasCommand('herd')
                    ? ['herd' => 'Laravel Herd - served at https://<name>.test']
                    : []),
            ],
            default: 'docker',
        );

        return Environment::make($name);
    }

    protected function captureStack(): void
    {
        // An application that already has Saucebase chose its framework when it was installed.
        $stack = $this->projectFramework() ?? $this->argument('stack');

        if (! $stack) {
            $stack = $this->isCI()
                ? 'vue'
                : select(
                    label: 'Which frontend stack would you like to use?',
                    options: ['vue' => 'Vue', 'react' => 'React'],
                    default: 'vue',
                );
        }

        $this->selectedStack = $stack;
    }

    protected function captureDomain(): void
    {
        $option = $this->option('domain');

        // An already-installed app's host is the default, so accepting the prompt keeps
        // it and answering something else genuinely changes it.
        $current = $this->envValue('APP_HOST') ?: 'localhost';

        $domain = match (true) {
            $option !== null && $option !== '' => $option,
            (bool) $this->option('force') => $current,
            default => text(
                label: 'Which hostname will you use for this app?',
                default: $current,
                hint: 'Use localhost, or a custom domain such as myapp.test.',
                validate: fn (string $value) => self::normalizeDomain($value) === null
                    ? 'Enter a bare hostname, e.g. myapp.test'
                    : null,
            ),
        };

        $this->domain = self::normalizeDomain($domain) ?? 'localhost';

        $this->warnWhenDomainDoesNotResolve($this->domain);
    }

    /** Read a key straight out of the target app's .env, or null when absent. */
    public function envValue(string $key): ?string
    {
        $env = @file_get_contents($this->path('.env'));

        return $env === false ? null : self::readEnvLine($env, $key);
    }

    /**
     * Reduce a user-entered value to a bare hostname, or null when it is not one.
     * Accepts a pasted URL: "https://My.App.test:8443/" becomes "my.app.test".
     */
    public static function normalizeDomain(string $value): ?string
    {
        $host = strtolower(trim($value));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = strtok($host, ':/');

        return ($host !== false && $host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) === 1)
            ? $host
            : null;
    }

    /**
     * A custom domain resolves only via /etc/hosts, Herd, Valet or dnsmasq. Say so
     * rather than letting the browser fail after a successful install.
     */
    protected function warnWhenDomainDoesNotResolve(string $domain): void
    {
        if ($domain === 'localhost' || gethostbyname($domain.'.') !== $domain.'.') {
            return;
        }

        $this->components->warn("\"{$domain}\" does not resolve on this machine yet.");
        $this->line('  Add it to /etc/hosts before opening the app:');
        $this->line("  <fg=yellow>127.0.0.1  {$domain}</>");
    }

    public function getDomain(): string
    {
        return $this->domain ?? 'localhost';
    }

    /** The framework Saucebase was installed with, or null when it is not installed yet. */
    public function projectFramework(): ?string
    {
        $project = json_decode((string) @file_get_contents($this->path('saucebase.json')), true);

        return $project['framework'] ?? null;
    }

    /**
     * What turns a plain Laravel application into a Saucebase one, in order. Empty when
     * Saucebase is already there, or in a contributor checkout, which runs the UI from core.
     *
     * @return list<list<string>>
     */
    public function coreInstallSteps(): array
    {
        if ($this->option('dev') || $this->projectFramework() !== null) {
            return [];
        }

        return [
            // Composer refuses an unlisted plugin outright when it cannot ask.
            ['composer', 'config', 'allow-plugins.saucebase/module-installer', 'true'],
            ['composer', 'require', 'saucebase/core', '--no-interaction'],
            // The browser tests core ships need its server side; a package's require-dev never reaches the app.
            ['composer', 'require', '--dev', 'saucebase/laravel-playwright', '--no-interaction'],
            ['php', 'artisan', 'saucebase:install', (string) $this->getSelectedStack(), '--force', '--no-interaction'],
        ];
    }

    public function installCore(): bool
    {
        foreach ($this->coreInstallSteps() as $step) {
            if (! $this->runProcess($step, 600)) {
                return false;
            }
        }

        return true;
    }

    /** @param  string[]  $command */
    public function runProcess(array $command, int $timeout = 300): bool
    {
        return Process::path($this->targetPath())
            ->timeout($timeout)
            ->run($command)
            ->successful();
    }

    /** A contributor checkout runs the UI from saucebase/core instead of owning a copy. */
    public function runStack(): void
    {
        if ($this->selectedStack && $this->option('dev')) {
            $this->call('stack', ['stack' => $this->selectedStack, '--path' => $this->targetPath(), '--dev' => true]);
        }
    }

    public function promptForModules(): void
    {
        if ($this->option('all-modules') || $this->option('modules') !== null || $this->option('dev') || $this->isCI()) {
            return;
        }

        $available = $this->fetchAvailableModules();

        if (empty($available)) {
            return;
        }

        $this->selectedModules = $this->registry()->promptSelection($available);
    }

    protected function registry(): ModuleRegistry
    {
        return $this->registry ??= new ModuleRegistry;
    }

    public function install(): int
    {
        if (! $this->ensureEnvFile()) {
            return self::FAILURE;
        }

        $this->applyAppIdentity();

        $this->generateApplicationKey();

        $installed = false;
        $this->task('Installing Saucebase', function () use (&$installed) {
            return $installed = $this->installCore();
        });

        if (! $installed) {
            return self::FAILURE;
        }

        $this->nameProject();

        if (! $this->setupDatabase()) {
            return self::FAILURE;
        }

        $this->runStack();

        if (! $this->setupModules()) {
            return self::FAILURE;
        }

        $this->createStorageLink();
        $this->clearCaches();

        return self::SUCCESS;
    }

    protected function handleCIInstallation(): int
    {
        $this->info('CI environment detected - running minimal setup...');

        $envOk = $this->ensureEnvFile();
        $keyOk = $this->envHasAppKey();

        $this->task('Verifying .env', fn () => $envOk);
        $this->task('Verifying app key', fn () => $keyOk);

        if (! $envOk || ! $keyOk) {
            return self::FAILURE;
        }

        $this->info('CI setup complete');

        return self::SUCCESS;
    }

    public function ensureEnvFile(): bool
    {
        if (file_exists($this->path('.env'))) {
            return true;
        }

        if (file_exists($this->path('.env.example'))) {
            if (! copy($this->path('.env.example'), $this->path('.env'))) {
                $this->error('Failed to copy .env.example to .env. Check directory permissions.');

                return false;
            }

            return true;
        }

        $this->error('.env file not found. Copy .env.example to .env and configure it before running the installer.');

        return false;
    }

    /**
     * Name the app after its own directory, and record the host it will be served on.
     *
     * A new application is called "Laravel" and has no APP_SLUG. Left alone, every app
     * would share a name and (since applyDockerEnvDefaults() derives them from APP_SLUG)
     * a database name too.
     */
    public function applyAppIdentity(bool $native = true): void
    {
        $path = $this->path('.env');
        $original = @file_get_contents($path);

        if ($original === false) {
            return;
        }

        $directory = basename($this->targetPath());

        $modified = $this->applyIdentityToEnv(
            $original,
            $this->appName(),
            Str::slug($directory),
            $this->getDomain(),
            native: $native,
        );

        if ($modified !== $original) {
            file_put_contents($path, $modified);
            $this->info('Application name and host written to .env.');
        }
    }

    /** What the app is called: the name asked for, or its directory's. */
    private function appName(): string
    {
        return $this->optionOrNull('app-name') ?: Str::headline(basename($this->targetPath()));
    }

    /**
     * Name the project after its directory in composer.json, package.json and the web
     * manifest, which arrive called `laravel/laravel`, `saucebase` and `Saucebase`.
     *
     * Only those defaults are replaced, and only that line: the files keep their formatting.
     */
    public function nameProject(): void
    {
        $slug = Str::slug(basename($this->targetPath()));
        $name = $this->appName();

        // File => key => [the name it arrives with, the project's own].
        $names = [
            'composer.json' => ['name' => ['laravel/laravel', "app/{$slug}"]],
            'package.json' => ['name' => ['saucebase', $slug]],
            'public/site.webmanifest' => ['name' => ['Saucebase', $name], 'short_name' => ['Saucebase', $name]],
        ];

        foreach ($names as $file => $keys) {
            $original = @file_get_contents($this->path($file));

            if ($original === false) {
                continue;
            }

            $modified = $original;

            foreach ($keys as $key => [$default, $own]) {
                $value = (string) json_encode($own, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $pattern = '/^(\s*"'.$key.'":\s*)'.preg_quote('"'.$default.'"', '/').'/m';
                $replace = fn (array $match): string => $match[1].$value;
                $modified = preg_replace_callback($pattern, $replace, $modified, 1) ?? $modified;
            }

            if ($modified !== $original) {
                file_put_contents($this->path($file), $modified);
            }
        }
    }

    /**
     * Only a default or a blank is replaced, so a re-run never clobbers a
     * value the user has since edited.
     *
     * @param  bool  $native  Whether to set APP_URL here; under Docker it is owned by
     *                        applyDockerEnvDefaults(), which also knows scheme and port.
     */
    protected function applyIdentityToEnv(string $env, string $name, string $slug, string $host, bool $native = false): string
    {
        $replaceable = [
            'APP_NAME' => [['Laravel', 'Saucebase'], str_contains($name, ' ') ? "\"{$name}\"" : $name],
            'APP_SLUG' => [['saucebase'], $slug],
        ];

        foreach ($replaceable as $key => [$defaults, $value]) {
            $current = self::readEnvLine($env, $key);

            if ($current === null || $current === '' || in_array($current, $defaults, true)) {
                $env = self::setEnvLine($env, $key, $value);
            }
        }

        // Always authoritative: the certificate and nginx config are generated from the
        // resolved domain, so APP_HOST must agree with them. A user's own value is not
        // lost — it is what captureDomain() offers as the prompt default.
        $env = self::setEnvLine($env, 'APP_HOST', $host);

        // Native serves on whatever laravel/installer already wrote for localhost
        // (http://localhost:8000, which is what `composer dev` binds); only a custom
        // host needs correcting here.
        if ($native && $host !== 'localhost') {
            $env = self::setEnvLine($env, 'APP_URL', 'http://'.$host);
        }

        return $env;
    }

    /** A key's value from raw .env text, or null when the key is absent. */
    public static function readEnvLine(string $env, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m) === 1
            ? trim(trim($m[1]), "\"'")
            : null;
    }

    /** Replace a key's value, appending the line when the key is absent. */
    public static function setEnvLine(string $env, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        return preg_match($pattern, $env)
            ? preg_replace($pattern, "{$key}={$value}", $env)
            : rtrim($env, "\n")."\n{$key}={$value}\n";
    }

    /** The password every demo account gets: the one in .env, or a new random one saved there. */
    public function demoPassword(): string
    {
        $env = (string) @file_get_contents($this->path('.env'));
        $password = self::readEnvLine($env, 'DEMO_PASSWORD');

        if ($password === null || $password === '') {
            $password = Str::password(10, symbols: false);
            file_put_contents($this->path('.env'), self::setEnvLine($env, 'DEMO_PASSWORD', $password));
        }

        return $password;
    }

    public function envHasAppKey(): bool
    {
        $env = @file_get_contents($this->path('.env'));

        return $env !== false && preg_match('/^APP_KEY=base64:.+$/m', $env) === 1;
    }

    protected function generateApplicationKey(): void
    {
        $this->task('Generating application key', function () {
            if ($this->envHasAppKey()) {
                return true;
            }

            return $this->runArtisan(['key:generate', '--force']);
        });
    }

    protected function setupDatabase(): bool
    {
        $fresh = $this->option('fresh');
        $label = $fresh ? 'Running migrate:fresh --seed' : 'Running migrations';
        $command = $fresh ? 'migrate:fresh' : 'migrate';
        $ok = false;

        $this->task($label, function () use ($command, &$ok) {
            return $ok = $this->runArtisan([$command, '--seed', '--force'], 300);
        });

        return $ok;
    }

    /** False when a selected module could not be wired into the application. */
    protected function setupModules(): bool
    {
        $opt = $this->option('modules');

        if ($opt === 'none') {
            return true;
        }

        // Fast path: skip Packagist discovery when all requested names are fully qualified
        if ($opt) {
            $names = array_values(array_filter(array_map(fn ($n) => strtolower(trim($n)), explode(',', $opt))));
            if ($names && ! array_filter($names, fn ($n) => ! str_contains($n, '/'))) {
                return $this->doInstallModules($names);
            }
        }

        if (! $opt && empty($this->selectedModules) && ! $this->option('all-modules')) {
            return true;
        }

        $available = $this->fetchAvailableModules();

        if (empty($available)) {
            $this->components->warn('Could not fetch module list from Packagist.');

            return true;
        }

        $selected = $this->resolveModuleSelection($available);

        if (empty($selected)) {
            return true;
        }

        return $this->doInstallModules($selected);
    }

    /**
     * Core's `saucebase:module-install` requires, patches, migrates and seeds the
     * modules. Every driver runs it; `$run` decides where (Docker passes one that runs
     * it in the app container), defaulting to this machine.
     *
     * @param  string[]  $selected
     * @param  (\Closure(string[] $command, int $timeout): bool)|null  $run
     */
    public function doInstallModules(array $selected, ?\Closure $run = null): bool
    {
        $run ??= fn (array $command, int $timeout): bool => $this->runArtisan(array_slice($command, 2), $timeout);

        if ($this->option('demo')) {
            $this->demoPassword();
        }

        $this->newLine();

        $ok = false;
        $this->task('Installing modules', function () use ($selected, $run, &$ok) {
            return $ok = $run([
                'php',
                'artisan',
                'saucebase:module-install',
                ...array_map(fn (string $package) => Str::after($package, '/'), $selected),
                '--no-interaction',
                ...($this->option('demo') ? ['--demo'] : []),
            ], 900);
        });

        if (! $ok) {
            $this->components->warn('Installing modules failed. Run it again to see why: php artisan saucebase:module-install '
                .implode(' ', array_map(fn (string $package) => Str::after($package, '/'), $selected)));
        }

        return $ok;
    }

    /**
     * @return string[]
     */
    public function fetchAvailableModules(): array
    {
        return $this->registry()->available();
    }

    /**
     * @param  string[]  $available
     * @return string[]
     */
    protected function resolveModuleSelection(array $available): array
    {
        // 1. Select all modules
        if ($this->option('all-modules')) {
            return $available;
        }

        // 2. Modules passed via --modules option
        if ($modules = $this->option('modules')) {
            $requested = collect(explode(',', $modules))
                ->map(fn ($m) => strtolower(trim($m)))
                ->filter()
                ->values();

            return collect($available)
                ->filter(function (string $package) use ($requested) {
                    return $requested->contains(strtolower($package))
                        || $requested->contains(strtolower(Str::after($package, '/')));
                })
                ->values()
                ->all();
        }

        return $this->selectedModules;
    }

    protected function createStorageLink(): void
    {
        $this->task('Creating storage link', fn () => $this->runArtisan(['storage:link']));
    }

    protected function clearCaches(): void
    {
        $this->task('Clearing caches', fn () => $this->runArtisan(['optimize:clear']));
    }

    /**
     * A progress line for one step. Laravel shows FAIL only for its own failure value
     * and DONE for anything else, `false` included, so a failed step looked done.
     */
    protected function task(string $label, callable $step): bool
    {
        $ok = false;
        $this->components->task($label, function () use ($step, &$ok) {
            return ($ok = (bool) $step())
                ? TaskResult::Success->value
                : TaskResult::Failure->value;
        });

        return $ok;
    }

    protected function isCI(): bool
    {
        return ! empty(getenv('CI'))
            || ! empty(getenv('GITHUB_ACTIONS'))
            || ! empty(getenv('GITLAB_CI'))
            || ! empty(getenv('CIRCLECI'))
            || ! empty(getenv('TRAVIS'));
    }

    public function displaySuccess(array $steps = []): void
    {
        callout(label: 'Installation complete', content: $this->successCalloutContent($steps));
    }

    /** @return array<int, string|ElementContract> */
    protected function successCalloutContent(array $steps): array
    {
        return array_filter([
            $steps ? 'You can start your local development using:' : null,
            $steps ? Element::numberedList(array_values($steps)) : null,
            // Plain: a hyperlink's escape codes count towards the box width and push its
            // border out, and terminals link a bare URL by themselves.
            'Learn more: https://github.com/saucebase-dev/saucebase',
        ]);
    }

    /** @param  array<string, string>  $resumeOptions */
    public function displayFailure(?string $step = null, array $resumeOptions = []): void
    {
        callout(label: 'Installation did not finish', content: $this->failureCalloutContent($step));

        // Printed outside the callout on purpose: Prompts hard-wraps box content to the
        // terminal width, and a wrapped command carries the box borders into whatever
        // the user pastes. This has to survive a copy-paste to be worth printing.
        $this->line('');
        $this->line($this->resumeCommand($resumeOptions));
        $this->line('');
    }

    /** @return array<int, string> */
    protected function failureCalloutContent(?string $step): array
    {
        return array_values(array_filter([
            $step ? "Failed at: {$step}" : null,
            'Your application directory is intact — nothing was rolled back.',
            'Fix the problem reported above, then resume with the command below.',
        ]));
    }

    /**
     * The exact command that resumes this install, with every answer baked in.
     *
     * `install` is idempotent (existing stubs, certs, .env and app key are all left
     * alone), so re-running it is the resume path. A bare `saucebase install` would
     * re-prompt for stack, driver and modules, hence every option is spelled out —
     * including the ones that came from prompts rather than the command line.
     *
     * @param  array<string, string>  $resumeOptions  Driver-resolved options (--driver, --ssl).
     */
    public function resumeCommand(array $resumeOptions = []): string
    {
        $command = 'saucebase install';

        if ($this->selectedStack) {
            $command .= ' '.$this->selectedStack;
        }

        foreach ($resumeOptions as $option => $value) {
            $command .= " {$option}={$value}";
        }

        if ($this->domain !== null) {
            $command .= ' --domain='.$this->domain;
        }

        if ($modules = $this->resumeModules()) {
            $command .= ' --modules='.$modules;
        }

        foreach (['all-modules', 'dev', 'fresh', 'force', 'demo'] as $flag) {
            if ($this->optionOrNull($flag)) {
                $command .= ' --'.$flag;
            }
        }

        $target = $this->targetPath();

        return $target === rtrim((string) getcwd(), '/')
            ? $command
            : 'cd '.$this->quotePath($target).' && '.$command;
    }

    /** The --modules value for a resume run, or null when selection was never resolved. */
    protected function resumeModules(): ?string
    {
        if ($this->optionOrNull('all-modules')) {
            return null;
        }

        if ($option = $this->optionOrNull('modules')) {
            return is_string($option) ? $option : null;
        }

        return $this->selectedModules ? implode(',', $this->selectedModules) : null;
    }

    /** Quote only when needed, but quote safely: paths can contain $, backticks or quotes. */
    protected function quotePath(string $path): string
    {
        return preg_match('#^[A-Za-z0-9._/@:+-]+$#', $path) === 1 ? $path : escapeshellarg($path);
    }
}

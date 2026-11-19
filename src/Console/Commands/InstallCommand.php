<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Contracts\Process\ProcessResult;
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
                            {--template= : A Saucebase template (vendor/name): installs it, the modules it requires, and its starting data}
                            {--make-template= : Build a template (vendor/name) in the new app, on the modules it installs}
                            {--state= : A template state to seed; --demo seeds its default}
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
        return $this->remember(Process::path($this->targetPath())
            ->timeout($timeout)
            ->run([PHP_BINARY, $this->path('artisan'), ...$args]));
    }

    /** The last lines a failed step wrote, so its warning can say why. */
    protected ?string $lastFailure = null;

    /** The install task that failed, named in the failure box when the driver didn't name a step. */
    protected ?string $failedTask = null;

    private function remember(ProcessResult $result): bool
    {
        $this->lastFailure = $result->successful() ? null : trim($result->output()."\n".$result->errorOutput());

        return $result->successful();
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

        // `saucebase-dev` (dev-tools) sets this to the local registry: core, modules and
        // templates then come from there at their dev branches. Unset: the published ones.
        $registry = getenv('SAUCEBASE_REGISTRY') ?: null;

        return [
            ...($registry === null ? [] : [
                ['composer', 'config', 'repositories.saucebase', 'composer', $registry],
                ['composer', 'config', 'minimum-stability', 'dev'],
                ['composer', 'config', 'prefer-stable', 'true'],
            ]),
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
        return $this->remember(Process::path($this->targetPath())
            ->timeout($timeout)
            ->run($command));
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
        // A template requires its own modules.
        if ($this->option('template') || $this->option('all-modules') || $this->option('modules') !== null || $this->option('dev') || $this->isCI()) {
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

        if (! $this->installSaucebase()) {
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

        if ($this->optionOrNull('make-template') && ! $this->makeTemplate()) {
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
            // Quoted when dotenv would misread it bare; inside the quotes, \ and " are escaped.
            'APP_NAME' => [['Laravel', 'Saucebase'], preg_match('/[\s"\\\\$#]/', $name) ? '"'.addcslashes($name, '"\\').'"' : $name],
            'APP_SLUG' => [['saucebase'], $slug],
        ];

        foreach ($replaceable as $key => [$defaults, $value]) {
            $current = self::readEnvLine($env, $key);

            if ($current === null || $current === '' || in_array($current, $defaults, true)) {
                $env = self::setEnvLine($env, $key, $value);
            }
        }

        // Read before APP_HOST is overwritten: a URL on the host chosen last time is ours.
        $previousHost = self::readEnvLine($env, 'APP_HOST');

        // Always authoritative: the certificate and nginx config are generated from the
        // resolved domain, so APP_HOST must agree with them. A user's own value is not
        // lost — it is what captureDomain() offers as the prompt default.
        $env = self::setEnvLine($env, 'APP_HOST', $host);

        // Only a URL the installer wrote follows the host, keeping its scheme and port: native's
        // localhost one is laravel/installer's http://localhost:8000, what `composer dev` binds.
        // Docker's localhost URL is applyDockerEnvDefaults()'s, which also sets scheme and port.
        $url = self::readEnvLine($env, 'APP_URL');
        $urlHost = $url === null ? null : parse_url($url, PHP_URL_HOST);
        $ours = array_filter([$native ? 'localhost' : null, $previousHost]);

        if (is_string($urlHost) && $urlHost !== $host && in_array($urlHost, $ours, true)) {
            $env = self::setEnvLine($env, 'APP_URL', (string) preg_replace('#^(https?://)[^/:]+#', '${1}'.$host, $url));
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

        // A callback, so the value is written as given: preg_replace would read $0 or \1 in it as backreferences.
        return preg_match($pattern, $env)
            ? preg_replace_callback($pattern, fn () => "{$key}={$value}", $env)
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

    /** Composer and core's own install; a failure says why, like every install step. */
    protected function installSaucebase(): bool
    {
        return $this->runInstallTask('Installing Saucebase', [], fn () => $this->installCore());
    }

    protected function setupDatabase(): bool
    {
        $fresh = $this->option('fresh');
        $label = $fresh ? 'Running migrate:fresh --seed' : 'Running migrations';
        $command = $fresh ? 'migrate:fresh' : 'migrate';

        return $this->runInstallTask($label, [$command, '--seed', '--force'], fn (array $args) => $this->runArtisan($args, 300));
    }

    /** False when a selected module could not be wired into the application. */
    protected function setupModules(): bool
    {
        // Its composer.json requires the modules; doInstallModules adds the template itself.
        if ($this->option('template')) {
            return $this->doInstallModules([]);
        }

        $opt = $this->option('modules');

        if ($opt === 'none') {
            return true;
        }

        // Named modules need no discovery: a short name is one of Saucebase's, as in
        // core's template-install. Private modules aren't on Packagist; Composer says
        // so if a name doesn't exist.
        if ($opt) {
            return $this->doInstallModules(collect(explode(',', $opt))
                ->map(fn (string $name) => strtolower(trim($name)))
                ->filter()
                ->map(fn (string $name) => str_contains($name, '/') ? $name : 'saucebase/'.$name)
                ->values()
                ->all());
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

        $this->newLine();

        // Core's template-install requires the template with its modules and copies its
        // stubs over the app. Its demo is its default state, seeded only when asked for
        // (--demo), like any demo; --state names another.
        if ($template = $this->optionOrNull('template')) {
            $state = $this->optionOrNull('state') ?: ($this->optionOrNull('demo') ? 'default' : null);

            // Only a seeded state creates accounts that need it.
            if ($state !== null) {
                $this->demoPassword();
            }

            $command = ['php', 'artisan', 'saucebase:template-install', $template, '--no-interaction',
                ...($state !== null ? ['--state='.$state] : [])];

            return $this->runInstallTask('Installing the template', $command, $run);
        }

        $state = $this->option('demo') ? 'showcase' : null;

        if ($state !== null) {
            $this->demoPassword();
        }

        return $this->runInstallTask('Installing modules', [
            'php',
            'artisan',
            'saucebase:module-install',
            ...array_map(fn (string $package) => Str::after($package, '/'), $selected),
            '--no-interaction',
            ...($state !== null ? ['--state='.$state] : []),
        ], $run);
    }

    /**
     * The app builds a template: core's template recipe makes it in modules/, on the
     * modules the app has, and Composer installs it there.
     *
     * @param  (\Closure(string[] $command, int $timeout): bool)|null  $run
     */
    public function makeTemplate(?\Closure $run = null): bool
    {
        $package = (string) $this->optionOrNull('make-template');

        if ($this->optionOrNull('template')) {
            $this->components->warn('An app takes one template: use --template or --make-template, not both.');

            return false;
        }

        [$vendor, $name] = str_contains($package, '/') ? explode('/', $package, 2) : ['', ''];

        // The name becomes the template's folder (kebab case) and its PHP namespace (Studly).
        if (! preg_match('#^[a-z0-9_.-]+$#', $vendor) || ! preg_match('#^[a-z][a-z0-9]*(-[a-z0-9]+)*$#', $name) || Str::kebab(Str::studly($name)) !== $name) {
            $this->components->warn('--make-template takes the template\'s package name, vendor/name in lowercase with dashes (acme/notes).');

            return false;
        }
        $run ??= fn (array $command, int $timeout): bool => $this->runProcess($command, $timeout);

        // A resumed install: the recipe already made it, so only Composer runs again.
        $existing = json_decode((string) @file_get_contents($this->path("modules/{$name}/composer.json")), true) ?? [];
        $made = ($existing['name'] ?? null) === $package && ($existing['type'] ?? null) === 'saucebase-template';

        return ($made || $this->runInstallTask('Creating the template', ['php', 'artisan', 'saucebase:recipe', Str::studly($name), 'template', '--vendor='.$vendor, '--no-interaction'], $run))
            && $this->runInstallTask('Installing the template', ['composer', 'update', $package, '--no-interaction'], $run);
    }

    /**
     * @param  string[]  $command
     * @param  \Closure(string[] $command, int $timeout): bool  $run
     */
    private function runInstallTask(string $label, array $command, \Closure $run): bool
    {
        $ok = false;
        $this->task($label, function () use ($command, $run, &$ok) {
            return $ok = $run($command, 900);
        });

        if (! $ok) {
            // ANSI codes and blank lines out; the end of the output is where the reason is.
            $why = collect(explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $this->lastFailure)))
                ->map(fn (string $line) => trim($line))->filter()
                // Composer's progress, per-package and usage lines bury the reason.
                ->reject(fn (string $line) => (bool) preg_match('/^(\d+(\/\d+)? \[|- (Installing|Downloading|Locking|Excluded)|require \[|Loading composer|Updating dependencies|Lock file operations|Writing lock file|Installing dependencies|Package operations|Generating .*autoload)/', $line))
                ->take(-12)->implode("\n");
            // The reason only: the failure box's resume command reruns this step and every
            // one after it (the build, HTTPS); re-running this one alone would skip them.
            $this->failedTask = $label;
            $this->components->warn("{$label} failed".($why !== '' ? ":\n{$why}" : '.'));
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
        $step ??= $this->failedTask;

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

        // Herd's choice is only on the command line; Docker's comes back resolved.
        if (! isset($resumeOptions['--ssl']) && ($ssl = $this->optionOrNull('ssl'))) {
            $resumeOptions['--ssl'] = $ssl;
        }

        foreach ($resumeOptions as $option => $value) {
            $command .= " {$option}={$value}";
        }

        // Free text: quoted, so a name with spaces or quotes survives the shell.
        if ($name = $this->optionOrNull('app-name')) {
            $command .= ' --app-name='.escapeshellarg($name);
        }

        if ($this->domain !== null) {
            $command .= ' --domain='.$this->domain;
        }

        if ($modules = $this->resumeModules()) {
            $command .= ' --modules='.$modules;
        }

        if ($template = $this->optionOrNull('template')) {
            $command .= ' --template='.$template;
        }

        if ($state = $this->optionOrNull('state')) {
            $command .= ' --state='.$state;
        }

        if ($make = $this->optionOrNull('make-template')) {
            $command .= ' --make-template='.$make;
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

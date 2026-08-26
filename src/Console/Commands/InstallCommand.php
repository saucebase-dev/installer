<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\Element;
use Laravel\Prompts\Elements\ElementContract;
use Saucebase\Installer\Console\Commands\Concerns\DisplaysBanner;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\ModuleRegistry;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use DisplaysBanner;

    protected $signature = 'install
                            {stack? : The frontend stack to install (vue or react)}
                            {--path= : The Saucebase application directory (defaults to the current directory)}
                            {--driver= : Environment driver (docker, native) — prompted if omitted}
                            {--ssl= : Enable HTTPS with mkcert for docker (yes/no) — prompted if omitted}
                            {--domain= : Hostname the app is served on (e.g. myapp.test) — prompted if omitted}
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
        $process = new Process([PHP_BINARY, $this->path('artisan'), ...$args], $this->targetPath());
        $process->setTimeout($timeout);
        $process->run();

        return $process->isSuccessful();
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
            ],
            default: 'docker',
        );

        return Environment::make($name);
    }

    protected function captureStack(): void
    {
        $stack = $this->argument('stack');

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
    protected function envValue(string $key): ?string
    {
        $env = @file_get_contents($this->path('.env'));

        return ($env !== false && preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $env, $m) === 1)
            ? trim(trim($m[1]), "\"'")
            : null;
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

    public function runStack(): void
    {
        if ($this->selectedStack) {
            $isDev = $this->option('dev') ? ['--dev' => true] : [];
            $this->call('stack', array_merge(
                ['stack' => $this->selectedStack, '--path' => $this->targetPath(), '--no-hint' => true],
                $isDev,
            ));
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

        if ($this->selectedStack) {
            $available = $this->filterModulesByFramework($available, $this->selectedStack);
        }

        if (empty($available)) {
            return;
        }

        $this->selectedModules = $this->registry()->promptSelection($available);
    }

    /**
     * Refuse to install a module that ships no frontend for the chosen stack.
     *
     * @param  string[]  $packages
     */
    public function assertModulesSupportStack(array $packages): bool
    {
        if (! $this->selectedStack || empty($packages)) {
            return true;
        }

        $incompatible = [];

        foreach ($packages as $package) {
            $frameworks = $this->fetchPackageFrameworks($package);

            if (! in_array($this->selectedStack, $frameworks, true)) {
                $incompatible[$package] = $frameworks;
            }
        }

        if (empty($incompatible)) {
            return true;
        }

        $this->error("These modules do not support the {$this->selectedStack} stack:");

        foreach ($incompatible as $package => $frameworks) {
            $this->line("  {$package} — supports: ".implode(', ', $frameworks));
        }

        $this->line('Drop them from --modules, or install with a stack they support.');

        return false;
    }

    /**
     * @param  string[]  $packages
     * @return string[]
     */
    public function filterModulesByFramework(array $packages, string $framework): array
    {
        return array_values(array_filter(
            $packages,
            fn (string $pkg) => in_array($framework, $this->fetchPackageFrameworks($pkg), true)
        ));
    }

    /**
     * @return string[]
     */
    protected function fetchPackageFrameworks(string $package): array
    {
        return $this->registry()->frameworks($package);
    }

    protected function registry(): ModuleRegistry
    {
        return $this->registry ??= new ModuleRegistry($this->modulesBasePath());
    }

    protected function modulesBasePath(): string
    {
        return $this->path('modules');
    }

    public function install(): int
    {
        if (! $this->ensureEnvFile()) {
            return self::FAILURE;
        }

        $this->applyAppIdentity();

        $this->generateApplicationKey();

        if (! $this->setupDatabase()) {
            return self::FAILURE;
        }

        $this->runStack();
        $this->setupModules();
        $this->rewriteCrossModuleImports();
        $this->createStorageLink();
        $this->clearCaches();

        return self::SUCCESS;
    }

    protected function handleCIInstallation(): int
    {
        $this->info('CI environment detected - running minimal setup...');

        $envOk = $this->ensureEnvFile();
        $keyOk = $this->envHasAppKey();

        $this->components->task('Verifying .env', fn () => $envOk);
        $this->components->task('Verifying app key', fn () => $keyOk);

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
     * The skeleton ships APP_NAME="Saucebase" / APP_SLUG=saucebase, and nothing used to
     * change them — so every app shared a name, and (since applyDockerEnvDefaults()
     * derives them from APP_SLUG) a database name too.
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
            Str::headline($directory),
            Str::slug($directory),
            $this->getDomain(),
            native: $native,
        );

        if ($modified !== $original) {
            file_put_contents($path, $modified);
            $this->info('Application name and host written to .env.');
        }
    }

    /**
     * Only a skeleton default or a blank is replaced, so a re-run never clobbers a
     * value the user has since edited.
     *
     * @param  bool  $native  Whether to set APP_URL here; under Docker it is owned by
     *                        applyDockerEnvDefaults(), which also knows scheme and port.
     */
    protected function applyIdentityToEnv(string $env, string $name, string $slug, string $host, bool $native = false): string
    {
        $replaceable = [
            'APP_NAME' => ['Saucebase', str_contains($name, ' ') ? "\"{$name}\"" : $name],
            'APP_SLUG' => ['saucebase', $slug],
        ];

        foreach ($replaceable as $key => [$default, $value]) {
            $current = preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)
                ? trim(trim($m[1]), "\"'")
                : null;

            if ($current === null || $current === '' || $current === $default) {
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

    /** Replace a key's value, appending the line when the key is absent. */
    public static function setEnvLine(string $env, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        return preg_match($pattern, $env)
            ? preg_replace($pattern, "{$key}={$value}", $env)
            : $env."\n{$key}={$value}";
    }

    public function envHasAppKey(): bool
    {
        $env = @file_get_contents($this->path('.env'));

        return $env !== false && preg_match('/^APP_KEY=base64:.+$/m', $env) === 1;
    }

    protected function generateApplicationKey(): void
    {
        $this->components->task('Generating application key', function () {
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

        $this->components->task($label, function () use ($command, &$ok) {
            return $ok = $this->runArtisan([$command, '--seed', '--force'], 300);
        });

        return $ok;
    }

    protected function setupModules(): void
    {
        $opt = $this->option('modules');

        if ($opt === 'none') {
            return;
        }

        // Fast path: skip Packagist discovery when all requested names are fully qualified
        if ($opt) {
            $names = array_values(array_filter(array_map(fn ($n) => strtolower(trim($n)), explode(',', $opt))));
            if ($names && ! array_filter($names, fn ($n) => ! str_contains($n, '/'))) {
                $this->doInstallModules($names);

                return;
            }
        }

        if (! $opt && empty($this->selectedModules) && ! $this->option('all-modules')) {
            return;
        }

        $available = $this->fetchAvailableModules();

        if (empty($available)) {
            $this->components->warn('Could not fetch module list from Packagist.');

            return;
        }

        $selected = $this->resolveModuleSelection($available);

        if (empty($selected)) {
            return;
        }

        $this->doInstallModules($selected);
    }

    protected function doInstallModules(array $selected): void
    {
        $this->newLine();

        // Phase 1: require all selected packages in one Composer run
        $ok = false;
        $this->components->task('Installing modules', function () use ($selected, &$ok) {
            $process = new Process(
                array_merge(['composer', 'require', '--no-interaction'], $selected),
                $this->targetPath(),
            );
            $process->setTimeout(300);
            $process->run();

            return $ok = $process->isSuccessful();
        });

        if (! $ok) {
            $this->components->warn('Module installation failed — skipping patches, sync, and migrations.');

            return;
        }

        // Phase 2: apply any patches the modules ship for the host app
        $this->applyModulePatches($selected);

        // Phase 3: sync module configs, then migrate + seed each module individually
        $this->components->task('Syncing modules', fn () => $this->runArtisan(['modules:sync'], 30));

        $this->components->task('Running module migrations', fn () => $this->runArtisan(['migrate', '--force'], 300));

        foreach ($selected as $package) {
            $name = Str::after($package, '/');

            if (! $this->moduleHasSeeder($name)) {
                continue;
            }

            $this->components->task("Seeding {$name}", function () use ($name) {
                return $this->runArtisan(['db:seed', "--module={$name}", '--force'], 60);
            });
        }
    }

    public function rewriteCrossModuleImports(): void
    {
        $frameworks = ['vue', 'react', 'svelte'];
        $pattern = implode('|', array_map(fn ($f) => preg_quote($f, '#'), $frameworks));
        $extensions = ['vue', 'ts', 'tsx', 'js'];
        $moduleDirs = glob($this->path('modules/*/resources/js'), GLOB_ONLYDIR) ?: [];

        foreach ($moduleDirs as $jsRoot) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jsRoot));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }
                $path = $file->getPathname();
                $content = file_get_contents($path);
                $rewritten = preg_replace(
                    "#(@modules/[^/]+/resources/js/)({$pattern})/#",
                    '$1',
                    $content
                );
                if ($rewritten !== $content) {
                    file_put_contents($path, $rewritten);
                }
            }
        }
    }

    public function moduleHasSeeder(string $name): bool
    {
        $seederFile = 'database/seeders/DatabaseSeeder.php';

        return file_exists($this->path('modules/'.strtolower($name).'/'.$seederFile))
            || file_exists($this->path('vendor/saucebase/'.strtolower($name).'/'.$seederFile));
    }

    public function applyModulePatches(array $modules): void
    {
        foreach ($modules as $package) {
            $name = Str::after($package, '/');

            $dirs = array_filter([
                $this->path("vendor/saucebase/{$name}/patches"),
                $this->path("modules/{$name}/patches"),
            ], 'is_dir');

            foreach ($dirs as $dir) {
                foreach (glob("{$dir}/*.patch") ?: [] as $patch) {
                    $label = basename($patch);

                    $check = new Process(['git', 'apply', '--check', '--whitespace=nowarn', $patch]);
                    $check->setWorkingDirectory($this->targetPath());
                    $check->run();

                    if (! $check->isSuccessful()) {
                        $this->warn("Skipping {$label}: already applied or conflicts.");

                        continue;
                    }

                    $apply = new Process(['git', 'apply', '--whitespace=nowarn', $patch]);
                    $apply->setWorkingDirectory($this->targetPath());
                    $apply->run();

                    if ($apply->isSuccessful()) {
                        $this->info("Applied patch: {$label}");
                    } else {
                        $this->warn("Failed to apply {$label}: ".$apply->getErrorOutput());
                    }
                }
            }
        }
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
        // 1. Select all modules (filtered to the chosen stack if one is set)
        if ($this->option('all-modules')) {
            return $this->selectedStack
                ? $this->filterModulesByFramework($available, $this->selectedStack)
                : $available;
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
        $this->components->task('Creating storage link', fn () => $this->runArtisan(['storage:link']));
    }

    protected function clearCaches(): void
    {
        $this->components->task('Clearing caches', fn () => $this->runArtisan(['optimize:clear']));
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
            'Learn more: '.Element::link('https://github.com/saucebase-dev/saucebase'),
        ]);
    }

    /** @param  array<string, string>  $resumeOptions */
    public function displayFailure(?string $step = null, array $resumeOptions = []): void
    {
        callout(label: 'Installation did not finish', content: $this->failureCalloutContent($step, $resumeOptions));
    }

    /**
     * @param  array<string, string>  $resumeOptions
     * @return array<int, string>
     */
    protected function failureCalloutContent(?string $step, array $resumeOptions): array
    {
        return array_values(array_filter([
            $step ? "Failed at: {$step}" : null,
            'Your application directory is intact — nothing was rolled back.',
            'Fix the problem reported above, then resume with:',
            '  '.$this->resumeCommand($resumeOptions),
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

        foreach (['all-modules', 'dev', 'fresh', 'force'] as $flag) {
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

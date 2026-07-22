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

class InstallCommand extends Command
{
    use DisplaysBanner;

    protected $signature = 'install
                            {stack? : The frontend stack to install (vue or react)}
                            {--path= : The Saucebase application directory (defaults to the current directory)}
                            {--driver= : Environment driver (docker, native) — prompted if omitted}
                            {--ssl= : Enable HTTPS with mkcert for docker (yes/no) — prompted if omitted}
                            {--fresh : Run migrate:fresh instead of migrate (destructive)}
                            {--all-modules : Enable and migrate all available modules without prompting}
                            {--modules= : Comma-separated list of modules to enable (e.g. Auth,Settings), or "none"}
                            {--dev : Dev environment}
                            {--force : Skip confirmations}
                            {--no-logo : Suppress the welcome banner}';

    protected $description = 'Install and configure an existing Saucebase application';

    protected ?string $selectedStack = null;

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
            try {
                $path = $this->option('path');
            } catch (\Throwable) {
                // No input bound (command instantiated outside the console app).
                $path = null;
            }

            $this->resolvedTargetPath = rtrim($path ?: getcwd(), '/');
        }

        return $this->resolvedTargetPath;
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
}

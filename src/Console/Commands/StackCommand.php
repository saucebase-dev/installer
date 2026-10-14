<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class StackCommand extends Command
{
    protected $signature = 'stack
                            {stack? : The frontend stack to install (vue or react)}
                            {--path= : The Saucebase application directory (defaults to the current directory)}
                            {--dev : Contributor dev mode — copy config files only and run the UI from saucebase/core}
                            {--reset : Reset to clean state with no framework selected}
                            {--sync : Contributor dev mode — copy resources/css edits back to saucebase/core}
                            {--force : With --reset, discard resources/css edits that are not in saucebase/core}
                            {--no-skip-worktree : Do not mark generated files as skip-worktree in git}
                            {--no-install : Skip npm install after selecting the framework (dev mode)}';

    protected $description = 'Select the frontend framework stack (vue or react) of a contributor checkout';

    private const CONFIG_FILES = ['package.json', 'vite.config.js', 'tsconfig.json', 'eslint.config.js', 'components.json'];

    private const LOCKFILE = 'package-lock.json';

    private const VIEW_FILES = ['app.blade.php'];

    private const SUPPORTED = ['vue', 'react'];

    /** Where saucebase/core keeps each framework's starter UI and config files, relative to the app root. */
    private const CORE_STACK = 'vendor/saucebase/core/stubs/stack';

    private ?string $basePath;

    private ?string $jsRoot;

    public function __construct(
        private readonly Filesystem $files,
        ?string $basePath = null,
        ?string $jsRoot = null,
    ) {
        parent::__construct();
        $this->basePath = $basePath;
        $this->jsRoot = $jsRoot;
    }

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?: ($this->basePath ?? getcwd());
        $this->jsRoot ??= $this->basePath.'/resources/js';

        // Every mode writes into this folder, so it has to be the checkout and not wherever the shell happens to be.
        if (! $this->files->isDirectory($this->basePath.'/vendor/saucebase/core')) {
            $this->error("{$this->basePath} has no vendor/saucebase/core. "
                .'Run this from the root of a Saucebase contributor checkout, or name it with --path.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            return $this->runSync();
        }

        if ($this->option('reset')) {
            return $this->runReset();
        }

        $framework = strtolower($this->argument('stack') ?? '');

        if ($framework === '') {
            $framework = select(
                label: 'Which frontend stack would you like to use?',
                options: array_combine(self::SUPPORTED, array_map('ucfirst', self::SUPPORTED)),
                default: 'vue',
            );
        }

        if (! in_array($framework, self::SUPPORTED)) {
            $this->error("Invalid framework '{$framework}'. Supported: ".implode(', ', self::SUPPORTED).'.');

            return self::FAILURE;
        }

        if (! $this->option('dev')) {
            $this->error('An application gets its framework from `php artisan saucebase:install`. '
                ."In a contributor checkout, run `saucebase stack {$framework} --dev`.");

            return self::FAILURE;
        }

        return $this->runDevMode($framework);
    }

    // -------------------------------------------------------------------------
    // Modes
    // -------------------------------------------------------------------------

    private function runDevMode(string $framework): int
    {
        $current = $this->getSelectedFramework();

        if ($current !== null) {
            $this->error("Framework already set to \"{$current}\". Run `saucebase stack --reset` first.");

            return self::FAILURE;
        }

        $this->copyConfigFiles($framework);
        $this->copyLockFile($framework);
        $this->copyViewFiles($framework);
        $this->copyCss();

        $ext = $this->appExtension($framework);
        $source = '../../'.self::CORE_STACK."/{$framework}/js";
        $this->files->put($this->jsRoot."/app.{$ext}", "import '{$source}/app';\n");
        $this->files->put($this->jsRoot."/ssr.{$ext}", "import '{$source}/ssr';\n");

        // module entry points must be written before skipGeneratedFiles so their paths are discovered
        $this->updateModuleEntryPoints($framework);

        $this->writeProjectJson($framework);
        $this->skipGeneratedFiles($framework);

        if (! $this->option('no-install')) {
            $this->runNpmInstall();
        }

        $this->info("Framework set to {$framework} (dev mode)");

        return self::SUCCESS;
    }

    private function runReset(): int
    {
        $framework = $this->getSelectedFramework();

        if ($framework === null) {
            $this->info('No framework selected — nothing to reset.');

            return self::SUCCESS;
        }

        if ($this->cssEdited() && ! $this->option('force')) {
            $this->error('resources/css has changes that are not in saucebase/core. Run `saucebase stack --sync` '
                .'to keep them, or `saucebase stack --reset --force` to discard them.');

            return self::FAILURE;
        }

        $this->info("Resetting framework '{$framework}'...");

        $this->restoreTrackedFiles($framework);

        foreach ($this->generatedFiles($framework) as $file) {
            $process = new Process(['git', '-C', $this->basePath, 'checkout', '--', $file]);
            $process->run();
            $exitCode = $process->getExitCode();

            if ($exitCode !== 0) {
                // Module files are tracked in their own repos — skip-worktree was already
                // cleared above; don't delete or warn, the content is already correct.
                if (str_starts_with($file, 'modules/')) {
                    continue;
                }

                $abs = $this->basePath.'/'.$file;
                if ($this->files->exists($abs)) {
                    $this->files->delete($abs);
                } else {
                    $this->warn("Could not restore {$file}.");
                }
            }
        }

        $lockfile = $this->basePath.'/'.self::LOCKFILE;
        if ($this->files->exists($lockfile)) {
            $this->files->delete($lockfile);
        }

        $this->info('Reset complete. Run: npm install to restore base dependencies.');

        return self::SUCCESS;
    }

    private function runSync(): int
    {
        if (($this->projectJson()['contributor'] ?? false) !== true) {
            $this->error('Syncing CSS back to saucebase/core only works in a contributor checkout '
                .'(`saucebase stack <framework> --dev`).');

            return self::FAILURE;
        }

        $this->files->deleteDirectory($this->coreCssDir());
        $this->files->copyDirectory($this->cssDir(), $this->coreCssDir());
        $this->rememberCss();

        $this->info('Copied resources/css to saucebase/core. Commit it there.');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // File operations
    // -------------------------------------------------------------------------

    /**
     * The CSS finds modules, node_modules and vendor by relative path, so it has to sit in
     * resources/css even in a contributor checkout, where the rest of the UI runs from core.
     */
    private function copyCss(): void
    {
        if (! $this->files->isDirectory($this->coreCssDir())) {
            return;
        }

        $this->files->deleteDirectory($this->cssDir());
        $this->files->copyDirectory($this->coreCssDir(), $this->cssDir());

        $this->rememberCss();
    }

    /** Whether resources/css differs from what was copied out of core. */
    private function cssEdited(): bool
    {
        $remembered = $this->cssHashFile();

        return $this->files->exists($remembered) && $this->files->get($remembered) !== $this->cssHash();
    }

    /** Lets --reset tell edits made here from a copy that is merely older than core's. */
    private function rememberCss(): void
    {
        $this->files->ensureDirectoryExists(dirname($this->cssHashFile()));
        $this->files->put($this->cssHashFile(), (string) $this->cssHash());
    }

    private function cssHashFile(): string
    {
        return $this->basePath.'/storage/framework/saucebase-css';
    }

    private function cssHash(): ?string
    {
        if (! $this->files->isDirectory($this->cssDir())) {
            return null;
        }

        $files = [];

        foreach ($this->files->allFiles($this->cssDir()) as $file) {
            $files[$file->getRelativePathname()] = md5_file($file->getPathname());
        }

        ksort($files);

        return md5(json_encode($files));
    }

    private function cssDir(): string
    {
        return $this->basePath.'/resources/css';
    }

    private function coreCssDir(): string
    {
        return $this->basePath.'/'.self::CORE_STACK.'/css';
    }

    private function copyConfigFiles(string $framework): void
    {
        $sourceDir = $this->stubDir($framework).'/config';

        foreach (self::CONFIG_FILES as $filename) {
            $source = $sourceDir.'/'.$filename;
            $destination = $this->basePath.'/'.$filename;

            if (! $this->files->exists($source)) {
                continue;
            }

            $this->files->copy($source, $destination);
        }
    }

    private function copyLockFile(string $framework): void
    {
        $source = $this->stubDir($framework).'/config/'.self::LOCKFILE;
        $destination = $this->basePath.'/'.self::LOCKFILE;

        if ($this->files->exists($source)) {
            $this->files->copy($source, $destination);
        }
    }

    private function copyViewFiles(string $framework): void
    {
        $sourceDir = $this->stubDir($framework).'/views';

        foreach (self::VIEW_FILES as $filename) {
            $source = $sourceDir.'/'.$filename;
            $destination = $this->basePath.'/resources/views/'.$filename;

            if (! $this->files->exists($source)) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->put($destination, $this->files->get($source));
        }
    }

    private function updateModuleEntryPoints(string $framework): void
    {
        $moduleDirs = glob($this->basePath.'/modules/*/', GLOB_ONLYDIR);

        if (! $moduleDirs) {
            return;
        }

        foreach ($moduleDirs as $moduleDir) {
            $jsRoot = $moduleDir.'resources/js';

            if (! $this->files->isDirectory($jsRoot.'/'.$framework)) {
                continue;
            }

            $this->files->put($jsRoot.'/app.ts', "export * from './{$framework}/app';\n");
        }
    }

    // -------------------------------------------------------------------------
    // Git helpers
    // -------------------------------------------------------------------------

    private function skipGeneratedFiles(string $framework): void
    {
        if ($this->option('no-skip-worktree')) {
            return;
        }

        foreach ($this->generatedFiles($framework) as $file) {
            $process = new Process(['git', '-C', $this->basePath, 'update-index', '--skip-worktree', $file]);
            $process->run();

            if ($process->getExitCode() !== 0) {
                $this->warn("Could not skip-worktree {$file} (not in git index).");
            }
        }
    }

    private function restoreTrackedFiles(string $framework): void
    {
        foreach ($this->generatedFiles($framework) as $file) {
            (new Process(['git', '-C', $this->basePath, 'update-index', '--no-skip-worktree', $file]))->run();
        }
    }

    // -------------------------------------------------------------------------
    // Node helpers
    // -------------------------------------------------------------------------

    protected function runNpmInstall(): void
    {
        $this->info('Running npm install...');
        $process = new Process(['npm', 'install'], $this->basePath);
        $process->setTimeout(300);
        $process->run(fn ($_type, $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->warn('npm install failed. Run manually: npm install');
        }
    }

    // -------------------------------------------------------------------------
    // State & utilities
    // -------------------------------------------------------------------------

    private function getSelectedFramework(): ?string
    {
        return $this->projectJson()['framework'] ?? null;
    }

    /** @return array<string, mixed> */
    private function projectJson(): array
    {
        $path = $this->basePath.'/saucebase.json';

        return $this->files->exists($path) ? (json_decode($this->files->get($path), true) ?? []) : [];
    }

    private function writeProjectJson(string $framework): void
    {
        $this->files->put(
            $this->basePath.'/saucebase.json',
            json_encode(
                [...$this->projectJson(), 'framework' => $framework, 'contributor' => true],
                JSON_PRETTY_PRINT,
            ).PHP_EOL
        );
    }

    private function stubDir(string $framework): string
    {
        return $this->basePath.'/'.self::CORE_STACK."/{$framework}";
    }

    private function appExtension(string $framework): string
    {
        return $framework === 'react' ? 'tsx' : 'ts';
    }

    /** @return string[] Repo-relative paths for all files written by this command. */
    private function generatedFiles(string $framework): array
    {
        $ext = $this->appExtension($framework);

        $files = [
            ...self::CONFIG_FILES,
            ...array_map(fn (string $f) => "resources/views/{$f}", self::VIEW_FILES),
            "resources/js/app.{$ext}",
            "resources/js/ssr.{$ext}",
            'saucebase.json',
        ];

        foreach (glob($this->basePath.'/modules/*/resources/js/app.ts') ?: [] as $path) {
            $files[] = str_replace($this->basePath.'/', '', $path);
        }

        return $files;
    }
}

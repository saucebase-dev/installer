<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

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

        $this->writeProjectJson($framework);
        $this->skipGeneratedFiles($framework);
        $this->writeCoreTsconfig($framework);

        if (! $this->option('no-install')) {
            $this->runNpmInstall();
        }

        $this->info("Framework set to {$framework} (dev mode)");

        // A template's own files (CSS, page shell, tsconfig) are core's to put back, not this command's.
        if (is_string($this->projectJson()['template'] ?? null)) {
            $this->line('Template workspace: run `'.$this->inWorkspace('php artisan saucebase:template-capture --restore').'` to put its files back for this stack.');
        }

        return self::SUCCESS;
    }

    /** A command for the workspace to run: from elsewhere (--path), it goes there first. */
    private function inWorkspace(string $command): string
    {
        return realpath($this->basePath) === realpath((string) getcwd())
            ? $command
            : 'cd '.escapeshellarg($this->basePath)." && {$command}";
    }

    private function runReset(): int
    {
        $framework = $this->getSelectedFramework();

        if ($framework === null) {
            $this->info('No framework selected — nothing to reset.');

            return self::SUCCESS;
        }

        if ($this->cssEdited() && ! $this->option('force')) {
            // A template's CSS belongs in its stubs, never in core: --sync would push it there.
            $this->error(is_string($this->projectJson()['template'] ?? null)
                ? 'resources/css differs from saucebase/core. Keep template changes with `'.$this->inWorkspace('php artisan saucebase:template-capture').'`, '
                    .'then run `'.$this->inWorkspace('saucebase stack --reset --force').'`.'
                : 'resources/css has changes that are not in saucebase/core. Run `saucebase stack --sync` '
                    .'to keep them, or `saucebase stack --reset --force` to discard them.');

            return self::FAILURE;
        }

        $this->info("Resetting framework '{$framework}'...");

        // What the workspace set itself (the template it builds, say) outlives the stack.
        $kept = array_diff_key($this->projectJson(), array_flip(['framework', 'contributor']));

        $this->restoreTrackedFiles($framework);

        foreach ($this->generatedFiles($framework) as $file) {
            $exitCode = Process::run(['git', '-C', $this->basePath, 'checkout', '--', $file])->exitCode();

            if ($exitCode !== 0) {
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

        $this->files->delete($this->coreTsconfigPath($framework));

        if ($kept !== []) {
            $this->files->put(
                $this->basePath.'/saucebase.json',
                json_encode([...$this->projectJson(), ...$kept], JSON_PRETTY_PRINT).PHP_EOL,
            );
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

    /**
     * A symlinked core's components live outside the app, where no tsconfig reaches them:
     * Storybook's docgen and editors find a file's tsconfig by walking up from its real
     * path. This one, beside the stack's code, extends the app's and points the aliases
     * and packages back at the app. Core git-ignores it: the paths are this machine's.
     */
    private function writeCoreTsconfig(string $framework): void
    {
        if (! is_link($this->basePath.'/vendor/saucebase/core')) {
            return;
        }

        $app = (string) realpath($this->basePath);
        $appConfig = json_decode($this->files->get($app.'/tsconfig.json'), true);

        // `paths` replaces the extended config's paths rather than merging, so all of them,
        // made absolute, plus every package from the app's node_modules.
        $paths = array_map(
            fn (array $targets) => array_map(fn (string $target) => $app.'/'.preg_replace('#^\./#', '', $target), $targets),
            $appConfig['compilerOptions']['paths'] ?? [],
        );
        $paths['*'] = [$app.'/node_modules/*', $app.'/node_modules/@types/*'];

        $config = [
            'extends' => $app.'/tsconfig.json',
            'compilerOptions' => [
                'paths' => $paths,
                // The files are read at their real path; following the vendor/ link back
                // would load each one twice.
                'preserveSymlinks' => false,
            ],
            'include' => ['**/*.ts', $framework === 'react' ? '**/*.tsx' : '**/*.vue'],
        ];

        $this->files->ensureDirectoryExists(dirname($this->coreTsconfigPath($framework)));
        $this->files->put(
            $this->coreTsconfigPath($framework),
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function coreTsconfigPath(string $framework): string
    {
        return $this->stubDir($framework).'/js/tsconfig.json';
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

    // -------------------------------------------------------------------------
    // Git helpers
    // -------------------------------------------------------------------------

    private function skipGeneratedFiles(string $framework): void
    {
        if ($this->option('no-skip-worktree')) {
            return;
        }

        foreach ($this->generatedFiles($framework) as $file) {
            // A generated file git doesn't track (an entry point, gitignored) has nothing to hide.
            if (! Process::run(['git', '-C', $this->basePath, 'ls-files', '--error-unmatch', $file])->successful()) {
                continue;
            }

            $result = Process::run(['git', '-C', $this->basePath, 'update-index', '--skip-worktree', $file]);

            if ($result->exitCode() !== 0) {
                $this->warn("Could not skip-worktree {$file}: ".trim($result->errorOutput()));
            }
        }
    }

    private function restoreTrackedFiles(string $framework): void
    {
        foreach ($this->generatedFiles($framework) as $file) {
            Process::run(['git', '-C', $this->basePath, 'update-index', '--no-skip-worktree', $file]);
        }
    }

    // -------------------------------------------------------------------------
    // Node helpers
    // -------------------------------------------------------------------------

    protected function runNpmInstall(): void
    {
        $this->info('Running npm install...');
        $result = Process::path($this->basePath)
            ->timeout(300)
            ->run(['npm', 'install'], fn ($_type, $buffer) => $this->output->write($buffer));

        if (! $result->successful()) {
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
            'saucebase.json',
        ];

        return $files;
    }
}

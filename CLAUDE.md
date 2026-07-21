# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`saucebase/installer` is a **globally-installed Composer CLI** (like `laravel/installer`) that creates and configures new Saucebase applications. It ships a `saucebase` binary (`bin/saucebase`) exposing three commands:

- `saucebase new <name>` — creates a new project (via `laravel/installer`) and runs the full install flow against it.
- `saucebase install` — runs the install flow against an existing Saucebase app in the current directory (used internally by `new`, and available standalone).
- `saucebase stack <vue|react>` — selects/switches the frontend framework in an app directory.

It is **not** a Laravel package — there is no service provider and no package discovery. It runs standalone via a minimal `Illuminate\Console\Application` (see `src/Console/Application.php`). Local PHP + Composer are the only universal prerequisites (same as `laravel/installer` itself). Docker stubs (`docker-compose.yml`, `Dockerfile`, `nginx.conf`, `php.ini`, `xdebug.ini`) live in `stubs/docker/` and are copied directly into the target app by `DockerEnvironment::publishStubs()` (no `vendor:publish`).

Install globally with `composer global require saucebase/installer`, then `saucebase new my-app`.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit --no-coverage

# Run a single test
./vendor/bin/phpunit --no-coverage --filter test_fetch_package_frameworks_reads_saucebase_extra_field

# Smoke-test the binary
./bin/saucebase list
```

## Architecture

**Bootstrap:** `bin/saucebase` finds the Composer autoloader (global or local), then `Saucebase\Installer\Console\Application::make()` builds a standalone `Illuminate\Console\Application` backed by `src/Console/Container.php` (a minimal container exposing `runningUnitTests()`, which the console prompt layer probes for). Commands are registered via `resolveCommands()`. The app version comes from `Composer\InstalledVersions` for `saucebase/installer`.

**Target-path model:** the old package ran *inside* a Laravel app and used `base_path()`. The CLI now operates on an external target directory. Every command takes a `--path` option (defaults to `getcwd()`); `InstallCommand::path()` / `targetPath()` resolve it, and artisan sub-steps run via subprocess through `InstallCommand::runArtisan([...])` (`php <path>/artisan ...` with `cwd` set), never in-process `Artisan::call()`.

### `NewCommand` (`saucebase new`)
1. `displayWelcome()` banner (shared trait `Concerns/DisplaysBanner`).
2. `checkForUpdates()` — GETs `repo.packagist.org/p2/saucebase/installer.json` (2s timeout, silent offline). **Warns** when outdated, **hard-blocks (FAILURE)** when a full major version behind. Skipped for dev versions.
3. Collects all prompts upfront (name → driver → SSL if docker → stack → modules) so the install runs unattended. Driver default comes from `~/.config/saucebase/config.json`, saved after the first successful install.
4. Prerequisite check for the chosen driver; on failure prints fix hints (Docker Desktop URL, or the php.new one-liner for the current OS).
5. `createProject()` — invokes `LaravelNewCommand` (a subclass of `laravel/installer`'s `NewCommand`) non-interactively with `--using=saucebase/saucebase --phpunit --no-node --no-boost --git`. The subclass no-ops `displayHeader()` and `checkForUpdate()` (Saucebase owns those).
6. Calls `install` against the created directory with all collected answers, then persists the driver preference.

### `InstallCommand` (`saucebase install`)
`handle()` shows the banner, captures the stack, then (unless CI) resolves the driver via `Environment::make()` and runs it. Drivers live in `src/Environments/` and extend the abstract `Environment` (which has the static `make()` factory, the `run()` template method, and `resolveModules()`).

**Native flow** (`NativeEnvironment::boot()` → `InstallCommand::install()`):
1. `ensureEnvFile()` — copies `.env.example` → `.env` if missing
2. `generateApplicationKey()` — skips if `APP_KEY` already set (`envHasAppKey()` reads the target `.env` directly)
3. `setupDatabase()` — `migrate` (or `migrate:fresh` with `--fresh`) with seed, via `runArtisan()`
4. `runStack()` — calls the `stack` command with `--path` pointing at the target app
5. `setupModules()` — Packagist discovery, one batched `composer require` (cwd = target), then `applyModulePatches()` → `modules:sync` → `migrate --force` → per-module `db:seed --module={name} --force`. `--modules=none` skips modules entirely.
6. `createStorageLink()` + `clearCaches()`

**Docker flow** (`DockerEnvironment::boot()`):
1. `promptForSsl()` — `--ssl=yes|no` if given, else `--force` ⇒ on, else prompt (requires mkcert)
2. SSL gate: requested but no `mkcert` → FAILURE with install hint
3. `publishStubs()` — **copies `stubs/docker/*` directly** into the target app (skips files that already exist); if SSL off, overwrites `docker/nginx.conf` with `nginx-no-ssl.conf`
4. `generateSsl()` — mkcert for `*.localhost` (no-op if disabled or certs exist)
5. `ensureEnvFile()` → `setDockerEnvDefaults()` → `applyDockerEnvDefaults()`: `DB_CONNECTION=mysql`, MySQL creds, `MAIL_MAILER=smtp`, `APP_URL=https://localhost` (or `http://` if SSL off)
6. `startDocker()` — `docker compose restart` + `up -d --wait --build` (30 min timeout, streaming), cwd = target
7. `runComposerInContainer()` → `generateAppKey()` → `runMigrations()` via `execInContainer()`
8. `runStack()` — **runs on the host** (files are volume-mounted) by delegating to `InstallCommand::runStack()`, not inside the container
9. `installModules()` — batched `composer require` in container → `applyModulePatches()` on host → `modules:sync` / `migrate` / per-module seed in container
10. `createStorageLink()` + `clearCaches()` in container

**`applyModulePatches(array $modules)`** (public on `InstallCommand`) — for each module looks for `*.patch` in `<path>/vendor/saucebase/{name}/patches/` and `<path>/modules/{name}/patches/`. `git apply --check` first (skip if applied/conflicts), then `git apply`, with working directory = target. `git apply` does not require a git repo, but `new` passes `--git` so one exists.

**`ModuleRegistry`** (`src/ModuleRegistry.php`) — shared by `new` and `install`. `available()` hits `packagist.org/packages/list.json?type=saucebase-module` (excludes abandoned). `frameworks($pkg)` reads `extra.saucebase.frameworks` from a local `modules/{name}/composer.json` if a modules path was given, else GitHub raw, defaulting to `['vue']`. `filterByFramework()` and `promptSelection()` complete the API.

**`StackCommand`** — Vue/React selection. Takes `--path`; `basePath`/`jsRoot` resolve from `--path` (or an injected constructor path in tests) at `handle()` time. Supports `--dev` (contributor mode — config only, keeps both dirs, uses git skip-worktree) and `--reset`.

### Drivers
`src/Environments/`: `Environment` (abstract base + `make()` factory), `DockerEnvironment`, `NativeEnvironment`. Add a driver (Valet, Herd, Sail) by extending `Environment` and adding a `match` arm to `Environment::make()`.

## Testing

Plain **PHPUnit** (no Testbench, no Laravel app). `tests/TestCase.php` builds the standalone console app and exposes `artisan($cli)` returning a `CommandResult` (`tests/CommandResult.php`) with `assertSuccessful()` / `assertFailed()` / `expectsOutputToContain()` / `doesntExpectOutputToContain()`. `bindCommand()` swaps in a stubbed command instance for a following `artisan()` call.

- `InstallCommandTest` — `fetchPackageFrameworks()`, `filterModulesByFramework()`, stack dispatch, driver selection, `--driver=native`, module resolution. `TestableInstallCommand` (bottom of file) exposes protected methods and stubs `doInstallModules`; `fakeOptions['path']` points file-touching tests at a temp dir.
- `StackCommandTest` — dev mode, install mode, reset, git skip-worktree, module/recipe stubs. Binds a `StackCommand` constructed with a temp `basePath`/`jsRoot` and a no-op `runNpmInstall()`.
- `Environments/NativeEnvironmentTest` — `run()` delegates to `install()` and passes the code through.
- `Environments/DockerEnvironmentTest` — `resolveModules()`, `applyDockerEnvDefaults()` (all SSL branches), SSL gate, `missingPrerequisites()`, `generateAppKey()` idempotency. `FakeInstallCommand` (bottom of file) stubs output + options; pass `['path' => $tmp]` for `.env`-reading tests.

## Relationship to the skeleton

The `saucebase/saucebase` skeleton must be published on Packagist for `--using=saucebase/saucebase` to resolve. The skeleton no longer depends on this package (`require-dev` dropped) — this is a **clean break**; existing apps that were installed with the old in-app package keep working as-is. Override the skeleton with `saucebase new my-app --using=vendor/other-skeleton`.

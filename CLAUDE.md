# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`saucebase/installer` is a Laravel package (dev dependency) that provides the `saucebase:install` and `saucebase:stack` Artisan commands. It also publishes Docker configuration files (`docker-compose.yml`, `Dockerfile`, `nginx.conf`, `php.ini`, `xdebug.ini`) to the host app via `vendor:publish --tag=saucebase-docker`.

`saucebase:install` bootstraps the entire dev environment — prompting for SSL, starting Docker, running explicit artisan steps in the container, applying module patches on the host, and running per-module migrations and seeders. Local PHP + Composer is a prerequisite (same as Laravel itself).

## Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit --no-coverage

# Run a single test
./vendor/bin/phpunit --no-coverage --filter test_fetch_package_frameworks_reads_saucebase_extra_field
```

## Architecture

**Entry point:** `InstallerServiceProvider` registers `InstallCommand` and `StackCommand` when running in console, and publishes Docker stubs under the `saucebase-docker` tag. Package discovery is automatic via `extra.laravel.providers` in `composer.json`.

**`InstallCommand`** selects an environment driver, then orchestrates the install flow:

- `--driver=docker` → `DockerEnvironment`: see Docker flow below.
- `--driver=native` (default when not prompted) → `NativeEnvironment`: delegates to `InstallCommand::install()`.

**Native install steps** (run by `NativeEnvironment` via `install()`):
1. `ensureEnvFile()` — copies `.env.example` → `.env` if missing
2. `generateApplicationKey()` — skips if `APP_KEY` already set
3. `setupDatabase()` — runs `migrate` (or `migrate:fresh` with `--fresh`) with seed
4. `runStack()` — calls `saucebase:stack` with the selected framework
5. `setupModules()` — fetches available modules from Packagist, batches all selected into one `composer require` call, then: `applyModulePatches()` → `modules:sync` → `migrate --force` (auto-discovered via InterNACHI/modular) → `db:seed --module={name} --force` per module
6. `createStorageLink()` + `clearCaches()`

**Docker flow** (`DockerEnvironment::run()`):
1. `promptForSsl()` — asks user whether to enable HTTPS (requires mkcert); `--force` defaults to SSL on
2. If SSL requested but `mkcert` not installed → hard failure with install hint
3. `publishStubs()` — `vendor:publish --tag=saucebase-docker`; if SSL off, overwrites `docker/nginx.conf` with `nginx-no-ssl.conf` stub
4. `generateSsl()` — runs mkcert for `*.localhost` (no-op if SSL disabled or certs already exist)
5. `ensureEnvFile()` — copies `.env.example` → `.env` if missing
6. `setDockerEnvDefaults()` — calls `applyDockerEnvDefaults()` to patch `.env`: `DB_CONNECTION=mysql`, MySQL credentials, `MAIL_MAILER=smtp`, `APP_URL=https://localhost` (or `http://` if SSL off)
7. `startDocker()` — `docker compose restart` + `docker compose up -d --wait --build` (30 min timeout, streaming output)
8. `runComposerInContainer()` — `composer install` in the `app` container
9. `generateAppKey()` → `runMigrations()` → `runStack()` — artisan steps in the container via `execInContainer()`
10. `installModules()` — single batched `composer require` for all modules in container → `applyModulePatches()` on host → `modules:sync` → `migrate --force` → `db:seed --module={name} --force` per module in container
11. `createStorageLink()` + `clearCaches()` in container
12. `reloadDocker()` — `docker compose up -d --wait`

**Stubs** live in `stubs/docker/`. Two nginx configs are shipped: `nginx.conf` (SSL, HTTPS on 443) and `nginx-no-ssl.conf` (plain HTTP on 80). `publishStubs()` always publishes the SSL version first, then overwrites with the no-SSL version if needed.

**`applyModulePatches(array $modules)`** (on `InstallCommand`, public) — for each module looks for `*.patch` files in `vendor/saucebase/{name}/patches/` and `modules/{name}/patches/`. Runs `git apply --check` first (skips if already applied), then `git apply`. Always runs on the host so git is available and volume-mounted changes are immediately visible.

**Environment drivers** live in `src/Environments/`. Each implements `Environments/Contracts/Environment` (`name()`, `label()`, `missingPrerequisites()`, `run(InstallCommand)`). Add new drivers (Valet, Herd, Sail) by creating a class there and adding a `match` arm in `InstallCommand::resolveDriver()`.

**`StackCommand`** manages frontend framework selection (Vue/React). Prompts when no stack argument is given. Supports `--dev` (contributor mode — copies config only, keeps both framework dirs) and `--reset`.

**Module discovery** hits `packagist.org/packages/list.json?type=saucebase-module` and filters by the `extra.saucebase.frameworks` field in each module's `composer.json` (falling back to GitHub raw if not on disk, defaulting to `['vue']`).

## Testing

Uses [Orchestral Testbench](https://github.com/orchestral/testbench) — no full Laravel app needed. `TestCase` in `tests/TestCase.php` registers `InstallerServiceProvider`.

- `InstallCommandTest` — covers `fetchPackageFrameworks()`, `filterModulesByFramework()`, stack dispatch, driver selection, and `--driver=native` behaviour. Uses anonymous class overrides to stub heavy operations without mocking internals. `TestableInstallCommand` at the bottom exposes protected methods for direct unit testing.
- `StackCommandTest` — covers dev mode, install mode, reset, git skip-worktree, module and recipe stub processing.
- `Environments/NativeEnvironmentTest` — verifies `run()` delegates to `install()` and passes through the return code.
- `Environments/DockerEnvironmentTest` — tests `resolveModules()`, `applyDockerEnvDefaults()` (all SSL/no-SSL branches), SSL gate in `run()`, and `missingPrerequisites()`. Uses `FakeInstallCommand` (at bottom of file) as a stub with no-op output methods.

## Wiring into the host app

```json
// saucebase/composer.json
"require-dev": { "saucebase/installer": "^2.0" }
```

Setup flow after `composer install`:
```bash
php artisan saucebase:install              # prompts for stack and driver
php artisan saucebase:install --driver=docker   # skip prompt, use Docker
php artisan saucebase:install --driver=native   # skip prompt, run natively
```

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`saucebase/installer` is a Laravel package (dev dependency) that provides the `saucebase:install` and `saucebase:stack` Artisan commands. It also publishes Docker configuration files (`docker-compose.yml`, `Dockerfile`, `nginx.conf`, `php.ini`, `xdebug.ini`) to the host app via `vendor:publish --tag=saucebase-docker`.

`saucebase:install` can bootstrap the entire dev environment — including starting Docker, running `composer install` inside the container, and building frontend assets — before handing off to the Laravel-specific install steps. Local PHP + Composer is a prerequisite (same as Laravel itself).

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

- `--driver=docker` → `DockerEnvironment`: publishes Docker stubs, generates SSL via mkcert, starts `docker compose`, runs `composer install` inside the container, re-invokes `saucebase:install --driver=native` inside the container, then runs `npm install && npm run build` on the host.
- `--driver=native` (default when not prompted) → `NativeEnvironment`: runs the Laravel install directly.

Laravel install steps (run by `NativeEnvironment` or inside the container for Docker):
1. `ensureEnvFile()` — copies `.env.example` → `.env` if missing
2. `generateApplicationKey()` — skips if `APP_KEY` already set
3. `setupDatabase()` — runs `migrate` (or `migrate:fresh` with `--fresh`) with seed
4. `runStack()` — calls `saucebase:stack` with the selected framework
5. `setupModules()` — fetches available modules from Packagist, `composer require`s selected ones, then runs `modules:sync` + `migrate`
6. `createStorageLink()` + `clearCaches()`

**Environment drivers** live in `src/Environments/`. Each implements `Environments/Contracts/Environment` (`name()`, `label()`, `run(InstallCommand)`). Add new drivers (Valet, Herd, Sail) by creating a class there and adding a `match` arm in `InstallCommand::resolveDriver()`.

**`StackCommand`** manages frontend framework selection (Vue/React). Prompts when no stack argument is given. Supports `--dev` (contributor mode — copies config only, keeps both framework dirs) and `--reset`.

**Module discovery** hits `packagist.org/packages/list.json?type=saucebase-module` and filters by the `extra.saucebase.frameworks` field in each module's `composer.json` (falling back to GitHub raw if not on disk, defaulting to `['vue']`).

## Testing

Uses [Orchestral Testbench](https://github.com/orchestral/testbench) — no full Laravel app needed. `TestCase` in `tests/TestCase.php` registers `InstallerServiceProvider`.

- `InstallCommandTest` — covers `fetchPackageFrameworks()`, `filterModulesByFramework()`, stack dispatch, driver selection, and `--driver=native` behaviour. Uses anonymous class overrides to stub heavy operations without mocking internals. `TestableInstallCommand` at the bottom exposes protected methods for direct unit testing.
- `StackCommandTest` — covers dev mode, install mode, reset, git skip-worktree, module and recipe stub processing.
- `Environments/NativeEnvironmentTest` — verifies `run()` delegates to `install()` and passes through the return code.
- `Environments/DockerEnvironmentTest` — unit-tests `buildContainerArgs()` for all flag-forwarding combinations using `FakeInstallCommand`.

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

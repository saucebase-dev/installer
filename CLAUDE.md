# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`saucebase/installer` is a Laravel package (dev dependency) that provides the `saucebase:install` Artisan command. It runs **inside the Docker container** after the environment is already up — it does not bootstrap Docker itself.

Docker files (`Dockerfile`, `docker-compose.yml`, `nginx.conf`, `php.ini`, `xdebug.ini`) and shell scripts (`docker.sh`, `ssl.sh`) live in the main `saucebase/` app repo and are committed there. This package does not publish or manage them.

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

**Entry point:** `InstallerServiceProvider` registers `InstallCommand` when running in console. Package discovery is automatic via `extra.laravel.providers` in `composer.json`.

**`InstallCommand`** orchestrates the full install flow:
1. `ensureEnvFile()` — copies `.env.example` → `.env` if missing
2. `generateApplicationKey()` — skips if `APP_KEY` already set
3. `setupDatabase()` — runs `migrate` (or `migrate:fresh` with `--fresh`) with seed
4. `runStack()` — delegates to `saucebase:stack` (lives in the host app, not this package)
5. `setupModules()` — fetches available modules from Packagist, `composer require`s selected ones, then runs `modules:sync` + `migrate`
6. `createStorageLink()` + `clearCaches()`

**Key design decision:** `saucebase:stack` is intentionally NOT in this package — it manages frontend file wiring (Vue/React) and belongs to the host app. `InstallCommand` calls it via `$this->call('saucebase:stack')`.

**Module discovery** hits `packagist.org/packages/list.json?type=saucebase-module` and filters by the `extra.saucebase.frameworks` field in each module's `composer.json` (falling back to GitHub raw if not on disk, defaulting to `['vue']`).

## Testing

Uses [Orchestral Testbench](https://github.com/orchestral/testbench) — no full Laravel app needed. `TestCase` in `tests/TestCase.php` registers `InstallerServiceProvider`.

Tests focus on the two methods with real logic: `fetchPackageFrameworks()` and `filterModulesByFramework()`. Stack dispatch tests use anonymous class overrides to stub heavy operations (`ensureEnvFile`, `setupDatabase`, etc.) without mocking internals.

`TestableInstallCommand` (at the bottom of `InstallCommandTest.php`) exposes protected methods for direct unit testing and supports injecting a custom `modulesBasePath` to avoid touching the real filesystem.

## Wiring into the host app

```json
// saucebase/composer.json
"require-dev": { "saucebase/installer": "@dev" },
"repositories": [{ "type": "path", "url": "../packages/installer", "options": { "symlink": true } }]
```

The identical test file lives in both repos — `saucebase/tests/Feature/InstallCommandTest.php` imports from `Saucebase\Installer\Console\Commands\InstallCommand`. Keep them in sync when modifying tests.

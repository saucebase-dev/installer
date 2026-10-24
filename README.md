# Saucebase Installer

![Tests](https://github.com/saucebase-dev/installer/actions/workflows/php.yml/badge.svg)
![PHP](https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&logoColor=white)
[![Saucebase](https://img.shields.io/packagist/v/saucebase/saucebase?color=5455c4&label=saucebase%2Fsaucebase)](https://packagist.org/packages/saucebase/saucebase)

The official CLI for creating [Saucebase](https://github.com/saucebase-dev/saucebase) applications — a globally-installed Composer package, like `laravel/installer`.

```bash
composer global require saucebase/installer
saucebase new my-app
```

`saucebase new` creates a new project and runs the full install flow (environment, database, frontend, modules) in one pass. No PHP yet? Use the one-command bootstrap at [install.saucebase.dev](https://install.saucebase.dev).

## Requirements

- PHP ^8.4 and Composer (the only universal prerequisites — same as Laravel itself)
- **Docker driver:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine with the Compose plugin; [mkcert](https://github.com/FiloSottile/mkcert) only if you enable SSL
- **Native driver:** a local PHP capable of running the app; the CLI points you to [php.new](https://php.new) if anything is missing
- **Herd driver:** [Laravel Herd](https://herd.laravel.com); offered in the prompt only when the `herd` command is found

The CLI **checks and instructs** for prerequisites — it never installs them for you.

## Installation

```bash
composer global require saucebase/installer
```

Make sure Composer's global bin directory is on your `PATH` (`composer global config bin-dir --absolute`).

To upgrade, run `composer global update saucebase/installer`. Running `require` again keeps the version you have. `saucebase new` also updates the installer itself when a newer release exists, then carries on with it.

## Commands

### `saucebase new <name>`

Creates a new Saucebase application and installs it end to end.

```bash
saucebase new my-app
```

Runs a self-update check, then collects every choice upfront (driver → SSL if Docker → stack → modules) so the install runs unattended. It scaffolds the app via `laravel/installer` (using the `saucebase/saucebase` starter kit), then runs the full install flow against it. Your driver choice is remembered in `~/.config/saucebase/config.json` as the default for next time.

**Options**

| Option | Description |
|--------|-------------|
| `--driver=docker\|native\|herd` | Environment driver (skips the prompt) |
| `--stack=vue\|react` | Frontend stack (skips the prompt) |
| `--ssl=yes\|no` | Enable HTTPS via mkcert, Docker only (skips the prompt) |
| `--modules=auth,billing` | Install specific modules by name, or `none` to skip modules |
| `--all-modules` | Install every module compatible with the stack |
| `--using=vendor/skeleton` | Use a different starter kit instead of `saucebase/saucebase` |
| `--fresh` | Run `migrate:fresh` instead of `migrate` (destructive) |
| `--force` | Overwrite an existing directory and skip confirmations |
| `--dev` | Contributor mode (uses the skeleton's dev branch, skips modules) |

**Examples**

```bash
# Fully interactive
saucebase new my-app

# Vue + Docker with SSL, all compatible modules
saucebase new my-app --driver=docker --ssl=yes --stack=vue --all-modules

# React + native PHP, no modules
saucebase new my-app --driver=native --stack=react --modules=none
```

---

### `saucebase install`

Runs the install flow against an **existing** Saucebase app. This is what `new` calls under the hood; run it standalone to (re)provision an app you already have.

```bash
cd my-app
saucebase install
```

Takes an optional `stack` argument (`vue`/`react`) and the same `--driver`, `--ssl`, `--modules`, `--fresh`, `--force` options as `new`, plus `--path` to target a directory other than the current one.

**Docker driver** — copies the Docker stubs (`docker-compose.yml`, `Dockerfile`, `nginx.conf`, …), generates SSL certs if enabled, patches `.env` with MySQL/Mailpit/`APP_URL` defaults, brings up `docker compose`, then generates `APP_KEY`, migrates, wires the frontend, and installs modules.

**Native driver** — copies `.env.example` → `.env`, generates `APP_KEY`, migrates and seeds, wires the frontend, installs modules, and links storage.

**Herd driver** — the native install, then `herd link` and (unless `--ssl=no`) `herd secure`, so the app is served at `https://<name>.test`. The name comes from a `.test` `--domain`, otherwise the directory. With `npm run dev` running, Vite renders pages on the server. With built assets only, Herd doesn't start the renderer, so pages render in the browser until you run `php artisan inertia:start-ssr`.

Every driver ends by building the frontend (`npm install && npm run build`) when npm is available, then checks the app boots before reporting the install complete.

---

### `saucebase stack <vue|react>`

Selects the frontend framework of a **contributor checkout**, one with `saucebase/core` in `vendor/`. An installed app has its framework chosen by `saucebase new` or `saucebase install` and does not use this command.

```bash
saucebase stack vue --dev
saucebase stack react --dev
```

Copies the framework's config (`package.json`, `vite.config.js`, `tsconfig.json`, …), lockfile, blade layout and CSS from `vendor/saucebase/core/stubs/stack` into place, and records the choice in `saucebase.json`. The UI source is not copied: the app reads it from `saucebase/core`.

**Options**

| Option | Description |
|--------|-------------|
| `--path=<dir>` | Target app directory (defaults to the current directory) |
| `--dev` | Contributor mode: copies the config files, runs the UI from `saucebase/core`, runs `npm install` |
| `--sync` | Copies `resources/css` edits back to `saucebase/core` |
| `--reset` | Reverts generated files to their pre-selection state |
| `--force` | With `--reset`, discards `resources/css` edits that are not in `saucebase/core` |
| `--no-skip-worktree` | Does not mark the generated files as `skip-worktree` in git |
| `--no-install` | Skips `npm install` |

## How it works

`saucebase` is a standalone `Illuminate\Console\Application` (not a Laravel package — no service provider). It operates on a target directory via `--path`, running artisan steps as subprocesses (`php <path>/artisan …`). Project creation is delegated to `laravel/installer`; everything after — environment, database, frontend, modules — is the Saucebase install flow.

## Troubleshooting

### SQLite: "database is locked"

The native and Herd drivers use SQLite. By default it doesn't wait when another request or a queue worker is writing, and the second write fails. In `config/database.php`, under `sqlite`, set:

```php
'busy_timeout' => 5000,
'journal_mode' => 'wal',
```

Writers then wait up to five seconds, and reads no longer block writes. The Docker driver uses MySQL and isn't affected.

## License

MIT

# saucebase/installer

![Tests](https://github.com/saucebase-dev/installer/actions/workflows/php.yml/badge.svg)
![PHP](https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Saucebase](https://img.shields.io/packagist/v/saucebase/saucebase?color=5455c4&label=saucebase%2Fsaucebase)](https://packagist.org/packages/saucebase/saucebase)

Dev-environment installer for [Saucebase](https://github.com/saucebase-dev/saucebase) applications.

Provides two Artisan commands: `saucebase:install` bootstraps the entire dev environment (Docker, dependencies, database, frontend), and `saucebase:stack` switches the frontend framework after the environment is running.

## Requirements

- PHP ^8.4
- Laravel ^13.0

## Installation

```bash
composer require --dev saucebase/installer
```

## Commands

### `saucebase:install`

Bootstraps a new Saucebase dev environment from scratch.

```bash
php artisan saucebase:install
```

Prompts for the frontend stack (Vue or React) and the environment driver (Docker or native PHP), then runs the full setup sequence.

**Docker driver** (`--driver=docker`):

1. Prompts whether to enable HTTPS via SSL (requires [mkcert](https://github.com/FiloSottile/mkcert))
2. Publishes Docker config files (`docker-compose.yml`, `Dockerfile`, `nginx.conf`, etc.)
3. Generates SSL certificates with mkcert (if SSL enabled)
4. Patches `.env` with Docker-appropriate defaults — MySQL credentials, `MAIL_MAILER=smtp` for Mailpit, and `APP_URL` matching the SSL choice
5. Starts `docker compose` and installs PHP dependencies inside the container
6. Generates `APP_KEY`, runs migrations, wires up the frontend stack
7. Installs any selected modules (`composer require` → patches → `modules:sync` → `modules:migrate` → `modules:seed` per module)
8. Creates the storage symlink and clears caches

**Native driver** (`--driver=native`):

1. Copies `.env.example` → `.env` and generates `APP_KEY`
2. Runs migrations and seeds the database
3. Wires up the selected frontend stack
4. Installs any selected modules (same patch + migrate + seed flow as Docker)
5. Creates the storage symlink and clears caches

**Docker prerequisites**

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine with Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert) — only if you choose SSL (`brew install mkcert`)
- npm

**Options**

| Option | Description |
|--------|-------------|
| `vue` / `react` | Frontend stack (positional argument — skips the prompt) |
| `--driver=docker\|native` | Environment driver (skips the prompt) |
| `--fresh` | Run `migrate:fresh` instead of `migrate` (destructive — wipes all data) |
| `--all-modules` | Install every available module for the selected stack without prompting |
| `--modules=auth,billing` | Install specific modules by name (comma-separated, short or full package name) |
| `--dev` | Contributor mode — skips module installation |
| `--force` | Skip confirmations (Docker driver defaults to SSL when forced) |
| `--no-logo` | Suppress the welcome banner |

**Examples**

Fully interactive — prompts for stack, driver, SSL, and modules:
```bash
php artisan saucebase:install
```

Vue + Docker, fresh database, all compatible modules:
```bash
php artisan saucebase:install vue --driver=docker --fresh --all-modules
```

React + native PHP, specific modules only:
```bash
php artisan saucebase:install react --driver=native --modules=auth,billing
```

---

### `saucebase:stack`

Selects or switches the frontend framework for an existing Saucebase installation.

```bash
php artisan saucebase:stack vue
php artisan saucebase:stack react
```

Copies the framework-specific JS source files, config files (`package.json`, `vite.config.js`, `tsconfig.json`, `eslint.config.js`, `components.json`), lockfile, and blade layout into place, then removes the unused framework's source directory. Writes `frontend.json` to record the selection.

> **Note:** Framework selection is permanent for a given installation. To switch, start a new project or use `--reset`.

**Options**

| Option | Description |
|--------|-------------|
| `vue` / `react` | Framework to activate (positional argument) |
| `--dev` | Contributor mode — copies config files only, keeps both framework source directories, runs `npm install` |
| `--reset` | Reverts generated files to their pre-selection state (restores from git, deletes `package-lock.json`) |
| `--no-skip-worktree` | Do not mark generated files as `skip-worktree` in git (dev mode only) |

**Examples**

Activate Vue (install mode — irreversible):
```bash
php artisan saucebase:stack vue
```

Activate React in contributor mode (keeps both source dirs):
```bash
php artisan saucebase:stack react --dev
```

Undo a previous `--dev` selection:
```bash
php artisan saucebase:stack --reset
```

## License

MIT

# saucebase/installer

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

Prompts for the frontend stack (Vue or React) and the environment driver (Docker or native PHP), then runs the full setup sequence:

1. Publishes Docker config files and generates SSL certificates (Docker driver only)
2. Starts `docker compose` and installs PHP dependencies inside the container
3. Copies `.env.example` → `.env` and generates `APP_KEY`
4. Runs migrations and seeds the database
5. Wires up the selected frontend stack
6. Installs any selected modules
7. Creates the storage symlink and clears caches

**Options**

| Option | Description |
|--------|-------------|
| `vue` / `react` | Frontend stack (positional argument — skips the prompt) |
| `--driver=docker\|native` | Environment driver (skips the prompt) |
| `--fresh` | Run `migrate:fresh` instead of `migrate` (destructive — wipes all data) |
| `--all-modules` | Install every available module for the selected stack without prompting |
| `--modules=auth,billing` | Install specific modules by name (comma-separated) |
| `--dev` | Contributor mode — skips module installation |
| `--force` | Skip confirmations |

**Examples**

Fully interactive — prompts for stack and driver:
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

Re-run inside an already-running container (skips Docker steps):
```bash
php artisan saucebase:install vue --driver=native --force
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

# saucebase/installer

Dev-environment installer for [Saucebase](https://github.com/saucebase-dev/saucebase) applications.

Provides the `saucebase:install` Artisan command, which runs inside the Docker container after the environment is up to handle app-level setup: `.env` scaffolding, app key generation, database migrations, module installation, and storage linking.

## Requirements

- PHP ^8.4
- Laravel ^12.0 or ^13.0

## Installation

```bash
composer require --dev saucebase/installer
```

## Usage

```bash
php artisan saucebase:install
php artisan saucebase:install vue --fresh
php artisan saucebase:install --all-modules
php artisan saucebase:install --modules=auth,billing
```

## License

MIT

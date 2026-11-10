<?php

namespace Saucebase\Installer\Environments;

use Illuminate\Support\Str;
use Saucebase\Installer\Console\Commands\InstallCommand;

/** The native install, served by Laravel Herd at `<site>.test` instead of `composer dev`. */
class HerdEnvironment extends NativeEnvironment
{
    public function name(): string
    {
        return 'herd';
    }

    public function label(): string
    {
        return 'Laravel Herd';
    }

    public function missingPrerequisites(): array
    {
        $missing = parent::missingPrerequisites();

        if (! $this->commandExists('herd')) {
            $missing[] = 'herd is not installed or not in PATH.';
        }

        return $missing;
    }

    protected function boot(InstallCommand $command): int
    {
        if (($result = parent::boot($command)) !== InstallCommand::SUCCESS) {
            return $result;
        }

        $site = $this->site($command);

        if (! $command->runProcess(['herd', 'link', $site])) {
            return $this->fail('Linking the site to Herd');
        }

        if ($this->secure($command) && ! $command->runProcess(['herd', 'secure', $site])) {
            return $this->fail('Securing the site with Herd');
        }

        $env = (string) file_get_contents($command->path('.env'));
        $env = InstallCommand::setEnvLine($env, 'APP_HOST', "{$site}.test");
        $scheme = $this->secure($command) ? 'https' : 'http';
        $env = InstallCommand::setEnvLine($env, 'APP_URL', "{$scheme}://{$site}.test");
        file_put_contents($command->path('.env'), $env);

        return InstallCommand::SUCCESS;
    }

    protected function nextSteps(InstallCommand $command): array
    {
        return [
            ...($this->frontendBuilt ? [] : ['Install frontend dependencies: `npm install`']),
            $this->frontendBuilt ? 'For live reload while you edit: `npm run dev`' : 'Start Vite: `npm run dev`',
            'Herd already serves your app: `'.($this->readEnvValue($command, 'APP_URL') ?? '').'`',
        ];
    }

    /** HTTPS unless `--ssl=no`: Herd trusts its own certificates, so there is nothing to install. */
    private function secure(InstallCommand $command): bool
    {
        return $command->optionOrNull('ssl') !== 'no';
    }

    /** `shop.test` from `--domain`, otherwise the directory name. */
    private function site(InstallCommand $command): string
    {
        $domain = $command->getDomain();

        return str_ends_with($domain, '.test')
            ? substr($domain, 0, -5)
            : Str::slug(basename($command->targetPath()));
    }
}

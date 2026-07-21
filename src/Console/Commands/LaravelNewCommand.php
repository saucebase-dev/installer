<?php

namespace Saucebase\Installer\Console\Commands;

use Laravel\Installer\Console\NewCommand as BaseNewCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * laravel/installer's NewCommand with the Laravel-specific chrome removed:
 * Saucebase shows its own banner, and the self-update check would compare
 * against the wrong package.
 */
class LaravelNewCommand extends BaseNewCommand
{
    protected function displayHeader(OutputInterface $output): void
    {
        // Saucebase displays its own banner.
    }

    protected function checkForUpdate(InputInterface $input, OutputInterface $output)
    {
        // Handled by Saucebase's own update check against saucebase/installer.
    }
}

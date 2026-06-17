<?php

namespace Saucebase\Installer\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Saucebase\Installer\InstallerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [InstallerServiceProvider::class];
    }
}

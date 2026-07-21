<?php

namespace Saucebase\Installer\Console;

use Illuminate\Container\Container as BaseContainer;

/**
 * Minimal container for running Illuminate console commands outside a
 * Laravel application. Only the methods the console layer probes for
 * beyond the container contract live here.
 */
class Container extends BaseContainer
{
    public function runningUnitTests(): bool
    {
        return false;
    }
}

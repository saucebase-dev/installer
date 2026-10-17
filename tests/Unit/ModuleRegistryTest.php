<?php

namespace Saucebase\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Saucebase\Installer\ModuleRegistry;
use Saucebase\Installer\Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_it_names_a_module_the_way_a_person_reads_it(string $package, string $label): void
    {
        $this->assertSame($label, (new ModuleRegistry)->label($package));
    }

    public static function labels(): array
    {
        return [
            ['saucebase/auth', 'Auth'],
            ['saucebase/coming-soon', 'Coming Soon'],
            ['saucebase/auth-pro', 'Auth Pro'],
        ];
    }
}

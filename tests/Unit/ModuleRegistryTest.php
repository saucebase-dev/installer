<?php

namespace Saucebase\Installer\Tests\Unit;

use Illuminate\Support\Facades\Http;
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

    public function test_a_local_module_names_its_frameworks_without_asking_the_network(): void
    {
        Http::fake(['*' => Http::response(['extra' => ['saucebase' => ['frameworks' => ['vue']]]])]);
        $modules = $this->localModule('auth', ['react']);

        $this->assertSame(['react'], (new ModuleRegistry($modules))->frameworks('saucebase/auth'));
        Http::assertNothingSent();
    }

    /** An empty list says nothing, so the published manifest decides. */
    public function test_a_local_module_with_an_empty_framework_list_falls_back_to_the_published_one(): void
    {
        Http::fake(['*' => Http::response(['extra' => ['saucebase' => ['frameworks' => ['react']]]])]);
        $modules = $this->localModule('auth', []);

        $this->assertSame(['react'], (new ModuleRegistry($modules))->frameworks('saucebase/auth'));
    }

    public function test_a_published_empty_framework_list_means_vue(): void
    {
        Http::fake(['*' => Http::response(['extra' => ['saucebase' => ['frameworks' => []]]])]);

        $this->assertSame(['vue'], (new ModuleRegistry)->frameworks('saucebase/auth'));
    }

    /** @param  string[]  $frameworks */
    private function localModule(string $name, array $frameworks): string
    {
        $dir = sys_get_temp_dir().'/sb-registry-'.uniqid();
        mkdir("{$dir}/{$name}", 0755, true);
        file_put_contents("{$dir}/{$name}/composer.json", json_encode(['extra' => ['saucebase' => ['frameworks' => $frameworks]]]));
        register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($dir)));

        return $dir;
    }
}

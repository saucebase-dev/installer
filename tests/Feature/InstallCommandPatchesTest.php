<?php

namespace Saucebase\Installer\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Saucebase\Installer\Tests\TestCase;

/**
 * A module wires itself into the application with patches. One that does not apply
 * leaves the module installed but not connected, so it has to stop the install.
 */
class InstallCommandPatchesTest extends TestCase
{
    private string $dir;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->dir = sys_get_temp_dir().'/sb-patches-'.uniqid();
        $this->files->ensureDirectoryExists($this->dir.'/app');
        $this->files->ensureDirectoryExists($this->dir.'/modules/auth/patches');

        $this->files->put($this->dir.'/modules/auth/patches/user.patch', <<<'PATCH'
            --- a/app/User.php
            +++ b/app/User.php
            @@ -1,3 +1,4 @@
             class User
             {
            +    use Sociable;
             }

            PATCH);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_patch_is_applied(): void
    {
        $this->files->put($this->dir.'/app/User.php', "class User\n{\n}\n");
        $command = $this->command();

        $this->assertTrue($command->applyModulePatches(['saucebase/auth']));
        $this->assertStringContainsString('use Sociable;', $this->files->get($this->dir.'/app/User.php'));
    }

    public function test_a_patch_already_applied_is_skipped_without_a_warning(): void
    {
        $this->files->put($this->dir.'/app/User.php', "class User\n{\n    use Sociable;\n}\n");
        $command = $this->command();

        $this->assertTrue($command->applyModulePatches(['saucebase/auth']));
        $this->assertSame([], $command->problems);
        $this->assertSame("class User\n{\n    use Sociable;\n}\n", $this->files->get($this->dir.'/app/User.php'));
    }

    public function test_a_patch_that_does_not_apply_fails_and_is_named(): void
    {
        $this->files->put($this->dir.'/app/User.php', "final class Account\n{\n    // rewritten by the application\n}\n");
        $command = $this->command();

        $this->assertFalse($command->applyModulePatches(['saucebase/auth']));
        $this->assertCount(1, $command->problems);
        $this->assertStringContainsString('modules/auth/patches/user.patch', $command->problems[0]);
    }

    public function test_modules_whose_patches_do_not_apply_fail_the_module_step(): void
    {
        $command = new class extends TestableInstallCommand
        {
            public function doInstallModules(array $selected, ?\Closure $run = null): bool
            {
                return false;
            }
        };
        $command->fakeOptions = ['path' => $this->dir, 'modules' => 'saucebase/auth'];

        $this->assertFalse($command->exposedSetupModules());
    }

    private function command(): TestableInstallCommand
    {
        $command = new class extends TestableInstallCommand
        {
            /** @var string[] */
            public array $problems = [];

            public function error($string, $verbosity = null): void
            {
                $this->problems[] = $string;
            }

            public function warn($string, $verbosity = null): void
            {
                $this->problems[] = $string;
            }

            public function info($string, $verbosity = null): void {}
        };
        $command->fakeOptions = ['path' => $this->dir];

        return $command;
    }
}

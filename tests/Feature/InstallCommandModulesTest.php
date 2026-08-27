<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Tests\TestCase;

/**
 * `composer require` exits non-zero when any post-update-cmd script fails, even though
 * the packages installed fine. The skeleton's documented `boost:update` hook does that
 * on every fresh install until Boost is configured, so the exit code alone must not
 * decide whether modules installed.
 */
class InstallCommandModulesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sb-modules-'.uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/composer.lock');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param string[] $locked */
    private function command(array $locked, bool $writeLock = true): TestableInstallCommand
    {
        if ($writeLock) {
            file_put_contents($this->dir.'/composer.lock', json_encode([
                'packages' => array_map(fn (string $name) => ['name' => $name], $locked),
                'packages-dev' => [['name' => 'laravel/boost']],
            ]));
        }

        $command = new class extends TestableInstallCommand
        {
            /** @var string[] */
            public array $messages = [];

            public function warn($string, $verbosity = null): void
            {
                $this->messages[] = $string;
            }

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->messages[] = $string;
            }
        };
        $command->fakeOptions = ['path' => $this->dir];

        return $command;
    }

    public function test_a_failed_post_install_script_does_not_fail_the_module_install(): void
    {
        $command = $this->command(['saucebase/auth', 'saucebase/settings']);

        $this->assertTrue($command->composerRequireSucceeded(false, ['saucebase/auth', 'saucebase/settings']));
        $this->assertStringContainsString('boost:install', implode("\n", $command->messages));
    }

    public function test_a_genuinely_missing_package_still_fails(): void
    {
        $command = $this->command(['saucebase/auth']);

        $this->assertFalse($command->composerRequireSucceeded(false, ['saucebase/auth', 'saucebase/never-resolved']));
    }

    public function test_a_clean_exit_is_taken_at_face_value_without_reading_the_lock(): void
    {
        $command = $this->command([], writeLock: false);

        $this->assertTrue($command->composerRequireSucceeded(true, ['saucebase/auth']));
        $this->assertSame([], $command->messages, 'a clean run must stay quiet');
    }

    public function test_a_missing_or_unreadable_lock_file_is_treated_as_failure(): void
    {
        $command = $this->command([], writeLock: false);

        $this->assertFalse($command->composerRequireSucceeded(false, ['saucebase/auth']));
    }

    public function test_package_names_are_matched_case_insensitively(): void
    {
        $command = $this->command(['saucebase/Auth']);

        $this->assertTrue($command->composerRequireSucceeded(false, ['Saucebase/auth']));
    }
}

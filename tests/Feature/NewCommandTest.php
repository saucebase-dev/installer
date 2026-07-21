<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Console\Commands\NewCommand;
use Saucebase\Installer\Tests\TestCase;

class NewCommandTest extends TestCase
{
    private function command(array $options = [], ?string $latest = '2.9.4'): TestableNewCommand
    {
        $command = new TestableNewCommand;
        $command->fakeOptions = $options;
        $command->fakeLatest = $latest;

        return $command;
    }

    public function test_skeleton_is_pinned_to_the_newest_published_release(): void
    {
        $this->assertSame(
            'saucebase/saucebase:2.9.4',
            $this->command()->exposedSkeletonPackage()
        );
    }

    public function test_skeleton_looks_up_the_skeleton_not_the_installer(): void
    {
        $command = $this->command();
        $command->exposedSkeletonPackage();

        $this->assertSame('saucebase/saucebase', $command->queriedPackage);
    }

    /**
     * laravel/installer forces --stability=dev, and `@stable` does not override it,
     * so the constraint must always be numeric or new apps silently get dev-main.
     */
    public function test_falls_back_to_a_numeric_constraint_when_offline(): void
    {
        $package = $this->command(latest: null)->exposedSkeletonPackage();

        $this->assertSame('saucebase/saucebase:^2.0', $package);
        $this->assertDoesNotMatchRegularExpression('/@(stable|dev)/', $package);
    }

    public function test_dev_mode_uses_the_unconstrained_package(): void
    {
        $this->assertSame(
            'saucebase/saucebase',
            $this->command(['dev' => true])->exposedSkeletonPackage()
        );
    }

    public function test_using_option_overrides_the_skeleton(): void
    {
        $this->assertSame(
            'vendor/other-skeleton',
            $this->command(['using' => 'vendor/other-skeleton'])->exposedSkeletonPackage()
        );
    }
}

class TestableNewCommand extends NewCommand
{
    /** @var array<string, bool|string|null> Fake option values for tests that bypass CLI input. */
    public array $fakeOptions = [];

    public function option($key = null): string|array|bool|null
    {
        if (! empty($this->fakeOptions)) {
            return $key !== null ? ($this->fakeOptions[$key] ?? false) : $this->fakeOptions;
        }

        return false;
    }

    /** Stubbed Packagist lookup — tests must not hit the network. */
    public ?string $fakeLatest = null;

    public ?string $queriedPackage = null;

    protected function latestVersion(string $package = 'saucebase/installer'): ?string
    {
        $this->queriedPackage = $package;

        return $this->fakeLatest;
    }

    public function exposedSkeletonPackage(): string
    {
        return $this->skeletonPackage();
    }
}

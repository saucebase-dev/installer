<?php

namespace Saucebase\Installer\Tests\Feature;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Saucebase\Installer\Console\Commands\NewCommand;
use Saucebase\Installer\Environments\Environment;
use Saucebase\Installer\Environments\HerdEnvironment;
use Saucebase\Installer\Environments\NativeEnvironment;
use Saucebase\Installer\Tests\TestCase;

class NewCommandTest extends TestCase
{
    /** @param  array<string, bool|string>  $options */
    private function command(array $options = []): TestableNewCommand
    {
        $command = new TestableNewCommand;
        $command->fakeOptions = $options;

        return $command;
    }

    public function test_a_new_application_starts_as_plain_laravel(): void
    {
        $this->assertArrayNotHasKey('--using', $this->command(['force' => true])->exposedProjectArguments('acme'));
    }

    public function test_using_starts_from_a_template_instead(): void
    {
        $this->assertSame(
            'vendor/template',
            $this->command(['using' => 'vendor/template'])->exposedProjectArguments('acme')['--using']
        );
    }

    public function test_a_contributor_starts_from_the_skeleton(): void
    {
        $this->assertSame(
            'saucebase/saucebase',
            $this->command(['dev' => true])->exposedProjectArguments('acme')['--using']
        );
    }

    /** A template brings its own framework, which is only known once its files are there. */
    public function test_the_chosen_app_name_reaches_the_install(): void
    {
        $command = $this->command(['force' => true]);

        $this->assertArrayNotHasKey('--app-name', $command->exposedInstallArguments('acme', 'vue'));

        $command->appName = 'Acme Shop';

        $this->assertSame('Acme Shop', $command->exposedInstallArguments('acme', 'vue')['--app-name']);
    }

    public function test_herd_is_secured_unless_ssl_is_turned_off(): void
    {
        $this->assertTrue($this->command([])->exposedSsl(new HerdEnvironment));
        $this->assertFalse($this->command(['ssl' => 'no'])->exposedSsl(new HerdEnvironment));
        $this->assertNull($this->command([])->exposedSsl(new NativeEnvironment));
    }

    /** Herd secures with `herd secure`: a machine without mkcert still gets its HTTPS app. */
    public function test_herd_does_not_need_mkcert(): void
    {
        $herd = new class extends HerdEnvironment
        {
            public function missingPrerequisites(): array
            {
                return [];
            }

            public function hasCommand(string $name): bool
            {
                return $name !== 'mkcert';
            }
        };
        $this->bindCommand(new class($herd) extends NewCommand
        {
            public function __construct(private Environment $herd)
            {
                parent::__construct();
            }

            protected function resolveDriver(string $name): Environment
            {
                return $this->herd;
            }

            // The step after the SSL check: reaching it is the point.
            protected function resolveDomain(string $name, Environment $driver): string
            {
                throw new \RuntimeException('past the SSL check');
            }
        });

        $this->expectExceptionMessage('past the SSL check');

        $this->artisan('new acme --driver=herd --stack=vue --modules=none -n');
    }

    public function test_the_folder_defaults_to_the_app_name_as_a_slug(): void
    {
        // The app's name, then Enter to take the folder it suggests.
        Prompt::fake([...str_split('Acme Shop'), Key::ENTER, Key::ENTER]);

        $this->assertSame(['acme-shop', 'Acme Shop'], $this->command([])->exposedNames(null));
    }

    public function test_herd_is_offered_at_the_apps_own_address(): void
    {
        $this->assertSame('Laravel Herd - served at https://acme-shop.test', $this->command([])->exposedDriverOptions('acme-shop')['herd']);
    }

    public function test_herd_serves_the_app_at_its_folder_name_without_asking(): void
    {
        $this->assertSame('acme-shop.test', $this->command([])->exposedResolveDomain('acme-shop', new HerdEnvironment));
    }

    public function test_demo_content_is_offered_and_declined_by_default(): void
    {
        Prompt::fake([Key::ENTER]);

        $this->assertFalse($this->command([])->exposedWantsDemo(['saucebase/blog']));
    }

    public function test_demo_content_is_loaded_when_accepted(): void
    {
        Prompt::fake(['y', Key::ENTER]);

        $this->assertTrue($this->command([])->exposedWantsDemo(['saucebase/blog']));
    }

    public function test_demo_content_is_not_offered_without_modules(): void
    {
        $this->assertFalse($this->command([])->exposedWantsDemo([]));
    }

    public function test_demo_content_is_not_offered_when_no_modules_are_asked_for(): void
    {
        $this->assertFalse($this->command(['modules' => 'none'])->exposedWantsDemo(null));
    }

    public function test_demo_reaches_the_install(): void
    {
        $command = $this->command(['force' => true]);

        $this->assertArrayNotHasKey('--demo', $command->exposedInstallArguments('acme', 'vue'));

        $command->demo = true;

        $this->assertTrue($command->exposedInstallArguments('acme', 'vue')['--demo']);
    }

    /** The template brings its modules; its demo is offered like any demo, declined by default. */
    public function test_a_saucebase_template_reaches_the_install_without_asking_for_modules(): void
    {
        $command = $this->command(['template' => 'saucebase/uptime', 'force' => true]);
        Prompt::fake([Key::ENTER]);

        $this->assertSame([], $command->exposedResolveModulesUpfront('vue'));
        $this->assertFalse($command->exposedWantsDemo([]));
        $this->assertSame('saucebase/uptime', $command->exposedInstallArguments('acme', 'vue')['--template']);
    }

    public function test_a_template_demo_is_offered(): void
    {
        $command = $this->command(['template' => 'saucebase/uptime', 'force' => true]);
        Prompt::fake(['y', Key::ENTER]);

        $this->assertTrue($command->exposedWantsDemo([]));
    }

    /** --state already chose what to seed: nothing to ask. */
    public function test_a_template_state_skips_the_demo_question(): void
    {
        $command = $this->command(['template' => 'saucebase/uptime', 'state' => 'agency', 'force' => true]);

        $this->assertFalse($command->exposedWantsDemo([]));
    }

    public function test_a_template_with_demo_passes_it_on(): void
    {
        $command = $this->command(['template' => 'saucebase/uptime', 'demo' => true, 'force' => true]);

        $this->assertTrue($command->exposedWantsDemo([]));
    }

    public function test_a_template_state_reaches_the_install(): void
    {
        $command = $this->command(['template' => 'saucebase/uptime', 'state' => 'agency', 'force' => true]);

        $this->assertSame('agency', $command->exposedInstallArguments('acme', 'vue')['--state']);
    }

    public function test_make_template_reaches_the_install(): void
    {
        $command = $this->command(['make-template' => 'acme/notes', 'force' => true]);

        $this->assertSame('acme/notes', $command->exposedInstallArguments('acme', 'vue')['--make-template']);
    }

    public function test_a_template_leaves_the_framework_and_modules_to_the_install(): void
    {
        $command = $this->command(['using' => 'vendor/template']);

        $this->assertNull($command->exposedResolveModulesUpfront(null));
        $this->assertArrayNotHasKey('stack', $command->exposedInstallArguments('acme', null));
        $this->assertSame('vue', $this->command(['force' => true])->exposedInstallArguments('acme', 'vue')['stack']);
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

    /** @return array<string, mixed> */
    public function exposedProjectArguments(string $name): array
    {
        return $this->projectArguments($name);
    }

    /** @return string[]|null */
    public function exposedResolveModulesUpfront(?string $stack): ?array
    {
        return $this->resolveModulesUpfront($stack);
    }

    /** @return array<string, mixed> */
    public function exposedInstallArguments(string $name, ?string $stack): array
    {
        return $this->installArguments($name, new NativeEnvironment, $stack, null, null, appName: $this->appName, demo: $this->demo);
    }

    public ?string $appName = null;

    public bool $demo = false;

    /** @param  string[]|null  $modules */
    public function exposedWantsDemo(?array $modules): bool
    {
        return $this->wantsDemo($modules);
    }

    /** @return array{0: string, 1: string|null} */
    public function exposedNames(?string $name): array
    {
        return $this->names($name);
    }

    /** @return array<string, string> */
    public function exposedDriverOptions(string $name): array
    {
        return $this->driverOptions($name, herd: true);
    }

    public function exposedResolveDomain(string $name, Environment $driver): string
    {
        return $this->resolveDomain($name, $driver);
    }

    public function exposedSsl(Environment $driver): ?bool
    {
        return $this->ssl($driver);
    }
}

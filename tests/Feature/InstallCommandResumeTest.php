<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Tests\TestCase;

class InstallCommandResumeTest extends TestCase
{
    /**
     * @param  array<string, bool|string|null>  $options
     * @param  string[]  $modules
     */
    private function command(array $options, ?string $stack = 'vue', array $modules = []): TestableInstallCommand
    {
        $command = new TestableInstallCommand;
        $command->fakeOptions = $options;
        $command->setSelectedStack($stack);
        $command->setSelectedModules($modules);

        return $command;
    }

    public function test_resume_command_bakes_in_every_answer_so_nothing_is_re_prompted(): void
    {
        $command = $this->command(
            ['path' => '/tmp/my-app-example'],
            modules: ['saucebase/auth', 'saucebase/billing'],
        );

        $this->assertSame(
            'cd /tmp/my-app-example && saucebase install vue --driver=docker --ssl=yes --modules=saucebase/auth,saucebase/billing',
            $command->resumeCommand(['--driver' => 'docker', '--ssl' => 'yes']),
        );
    }

    public function test_resume_command_prefers_the_modules_option_over_the_prompted_selection(): void
    {
        $command = $this->command(
            ['path' => '/tmp/app', 'modules' => 'none'],
            modules: ['saucebase/auth'],
        );

        $this->assertStringContainsString('--modules=none', $command->resumeCommand());
        $this->assertStringNotContainsString('saucebase/auth', $command->resumeCommand());
    }

    public function test_resume_command_omits_modules_when_all_modules_is_set(): void
    {
        $resume = $this->command(['path' => '/tmp/app', 'all-modules' => true])->resumeCommand();

        $this->assertStringContainsString('--all-modules', $resume);
        $this->assertStringNotContainsString('--modules=', $resume);
    }

    public function test_resume_command_carries_the_flags_through(): void
    {
        $resume = $this->command(['path' => '/tmp/app', 'dev' => true, 'fresh' => true, 'force' => true])->resumeCommand();

        $this->assertStringContainsString('--dev', $resume);
        $this->assertStringContainsString('--fresh', $resume);
        $this->assertStringContainsString('--force', $resume);
    }

    public function test_resume_command_drops_the_cd_when_the_target_is_the_current_directory(): void
    {
        $resume = $this->command(['path' => getcwd()])->resumeCommand(['--driver' => 'native']);

        $this->assertSame('saucebase install vue --driver=native', $resume);
    }

    public function test_resume_command_quotes_a_path_containing_spaces(): void
    {
        $resume = $this->command(['path' => '/tmp/my app'])->resumeCommand();

        $this->assertStringStartsWith("cd '/tmp/my app' && saucebase install", $resume);
    }

    public function test_resume_command_escapes_shell_metacharacters_in_the_path(): void
    {
        // A pasted command must not expand or execute anything from the path.
        $resume = $this->command(['path' => '/tmp/a$(whoami)`id`;rm'])->resumeCommand();

        $this->assertStringStartsWith("cd '/tmp/a\$(whoami)`id`;rm' && ", $resume);
    }

    public function test_resume_command_leaves_an_ordinary_path_unquoted(): void
    {
        $this->assertStringStartsWith(
            'cd /tmp/my-app-example && ',
            $this->command(['path' => '/tmp/my-app-example'])->resumeCommand(),
        );
    }

    public function test_failure_callout_names_the_step(): void
    {
        $content = $this->command(['path' => '/tmp/my-app-example'])
            ->exposedFailureCalloutContent('Starting Docker services');

        $this->assertSame('Failed at: Starting Docker services', $content[0]);
        $this->assertStringContainsString('directory', implode(' ', $content));
    }

    public function test_failure_callout_omits_the_step_line_when_the_step_is_unknown(): void
    {
        $content = $this->command(['path' => '/tmp/app'])->exposedFailureCalloutContent(null);

        $this->assertStringNotContainsString('Failed at:', implode(' ', $content));
    }

    public function test_the_resume_command_is_never_wrapped_inside_the_callout(): void
    {
        // Prompts hard-wraps callout content and the borders end up in the paste, so
        // the command must be printed outside the box.
        $content = $this->command(['path' => '/tmp/my-app-example'])
            ->exposedFailureCalloutContent('Running migrations');

        foreach ($content as $line) {
            $this->assertStringNotContainsString('saucebase install', $line);
        }
    }
}

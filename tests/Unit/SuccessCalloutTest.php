<?php

namespace Saucebase\Installer\Tests\Unit;

use Saucebase\Installer\Console\Commands\InstallCommand;
use Saucebase\Installer\Tests\TestCase;

class SuccessCalloutTest extends TestCase
{
    public function test_the_learn_more_link_is_plain_text_so_the_box_lines_up(): void
    {
        $command = new class extends InstallCommand
        {
            public function __construct() {}

            /** @return array<int, mixed> */
            public function content(): array
            {
                return $this->successCalloutContent([]);
            }
        };

        // A terminal hyperlink's escape codes count towards the callout's width and
        // push its right border out; terminals link a bare URL by themselves.
        $this->assertSame(['Learn more: https://github.com/saucebase-dev/saucebase'], array_values($command->content()));
    }
}

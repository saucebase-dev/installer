<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Console\Commands\Concerns\DisplaysBanner;
use Saucebase\Installer\Tests\TestCase;

class DisplaysBannerTest extends TestCase
{
    public function test_the_banner_fits_a_default_80_column_terminal(): void
    {
        $banner = new class
        {
            use DisplaysBanner;

            /** @var string[] */
            public array $lines = [];

            public function line(string $line): void
            {
                $this->lines[] = $line;
            }

            public function newLine(int $count = 1): void {}

            public function show(): void
            {
                $this->displayWelcome();
            }
        };

        $banner->show();

        foreach ($banner->lines as $line) {
            $this->assertLessThan(80, mb_strlen(strip_tags($line)), strip_tags($line));
        }
    }
}

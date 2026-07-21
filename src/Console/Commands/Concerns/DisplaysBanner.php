<?php

namespace Saucebase\Installer\Console\Commands\Concerns;

trait DisplaysBanner
{
    protected function displayWelcome(): void
    {
        $primary = '#5455c4';
        $secondary = '#26b9d9';
        $split = 48;

        $lines = [
            '                                                888                                 ',
            '                                                888                                 ',
            '                                                888                                 ',
            '    .d8888b   8888b.  888  888  .d8888b .d88b.  88888b.   8888b.  .d8888b   .d88b.  ',
            '    88K          "88b 888  888 d88P"   d8P  Y8b 888 "88b     "88b 88K      d8P  Y8b ',
            '    "Y8888b. .d888888 888  888 888     88888888 888  888 .d888888 "Y8888b. 88888888 ',
            '         X88 888  888 Y88b 888 Y88b.   Y8b.     888 d88P 888  888      X88 Y8b.     ',
            '     88888P\' "Y888888  "Y88888  "Y8888P "Y8888  88888P"  "Y888888  88888P\'  "Y8888  ',
        ];

        $this->newLine();

        foreach ($lines as $line) {
            $sauce = substr($line, 0, $split);
            $base = substr($line, $split);
            $this->line("<fg={$secondary}>{$sauce}</><fg={$primary}>{$base}</>");
        }

        $this->displayTagline();
    }

    protected function displayTagline(): void
    {
        $primary = '#5455c4';
        $logoWidth = 84;
        $slogan = 'With Saucebase • Your foundation is ready!';

        $padding = '<fg=white;bg='.$primary.'>'.str_repeat(' ', $logoWidth).'</>';
        $tagline = '<fg=white;bg='.$primary.';options=bold>'.mb_str_pad($slogan, $logoWidth, ' ', STR_PAD_BOTH).'</>';

        $this->newLine(2);
        $this->line($padding);
        $this->line($tagline);
        $this->line($padding);
        $this->newLine();
        $this->newLine();
    }
}

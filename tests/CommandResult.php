<?php

namespace Saucebase\Installer\Tests;

use PHPUnit\Framework\Assert;

class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}

    public function assertSuccessful(): static
    {
        Assert::assertSame(0, $this->exitCode, "Command failed with exit code {$this->exitCode}.\n{$this->output}");

        return $this;
    }

    public function assertFailed(): static
    {
        Assert::assertNotSame(0, $this->exitCode, "Command unexpectedly succeeded.\n{$this->output}");

        return $this;
    }

    public function expectsOutputToContain(string $needle): static
    {
        Assert::assertStringContainsString($needle, $this->output);

        return $this;
    }

    public function doesntExpectOutputToContain(string $needle): static
    {
        Assert::assertStringNotContainsString($needle, $this->output);

        return $this;
    }
}

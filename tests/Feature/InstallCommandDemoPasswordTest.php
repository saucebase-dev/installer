<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Tests\TestCase;

class InstallCommandDemoPasswordTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/sb-demo-password-'.uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function command(): TestableInstallCommand
    {
        $command = new TestableInstallCommand;
        $command->fakeOptions = ['path' => $this->tmp];

        return $command;
    }

    public function test_a_random_demo_password_is_saved_to_the_env(): void
    {
        file_put_contents($this->tmp.'/.env', "APP_NAME=Acme\n");

        $password = $this->command()->demoPassword();

        $this->assertSame(10, strlen($password));
        $this->assertStringContainsString("DEMO_PASSWORD={$password}\n", (string) file_get_contents($this->tmp.'/.env'));

        file_put_contents($this->tmp.'/.env', '');
        $this->assertNotSame($password, $this->command()->demoPassword(), 'Every install gets its own password.');
    }

    public function test_a_demo_password_already_in_the_env_is_kept(): void
    {
        file_put_contents($this->tmp.'/.env', "DEMO_PASSWORD=chosen-by-me\n");

        $this->assertSame('chosen-by-me', $this->command()->demoPassword());
        $this->assertSame("DEMO_PASSWORD=chosen-by-me\n", file_get_contents($this->tmp.'/.env'));
    }
}

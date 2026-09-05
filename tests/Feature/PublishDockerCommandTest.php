<?php

namespace Saucebase\Installer\Tests\Feature;

use Saucebase\Installer\Tests\TestCase;

class PublishDockerCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/sb-docker-publish-'.uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_it_publishes_the_docker_files(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        foreach (['docker-compose.yml', 'docker/Dockerfile', 'docker/nginx.conf', 'docker/php.ini', 'docker/xdebug.ini'] as $file) {
            $this->assertFileExists($this->tmp.'/'.$file);
        }
    }

    public function test_it_keeps_existing_files_when_not_forced(): void
    {
        file_put_contents($this->tmp.'/docker-compose.yml', 'mine');

        $this->artisan("docker:publish --path={$this->tmp}")
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped docker-compose.yml');

        $this->assertSame('mine', file_get_contents($this->tmp.'/docker-compose.yml'));
    }

    public function test_force_overwrites_existing_files(): void
    {
        file_put_contents($this->tmp.'/docker-compose.yml', 'mine');

        $this->artisan("docker:publish --path={$this->tmp} --force")->assertSuccessful();

        $this->assertNotSame('mine', file_get_contents($this->tmp.'/docker-compose.yml'));
    }

    public function test_ssl_no_publishes_the_plain_http_nginx_config(): void
    {
        $this->artisan("docker:publish --path={$this->tmp} --ssl=no")->assertSuccessful();

        $this->assertSame(
            file_get_contents(dirname(__DIR__, 2).'/stubs/docker/docker/nginx-no-ssl.conf'),
            file_get_contents($this->tmp.'/docker/nginx.conf'),
        );
    }

    public function test_ssl_yes_publishes_the_ssl_nginx_config(): void
    {
        $this->artisan("docker:publish --path={$this->tmp} --ssl=yes")->assertSuccessful();

        $this->assertSame(
            file_get_contents(dirname(__DIR__, 2).'/stubs/docker/docker/nginx.conf'),
            file_get_contents($this->tmp.'/docker/nginx.conf'),
        );
    }
}

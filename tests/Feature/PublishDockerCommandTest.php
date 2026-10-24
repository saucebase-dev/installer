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

    public function test_the_image_can_process_images(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        // Without GD the media library cannot make image conversions (blog covers, avatars).
        $dockerfile = (string) file_get_contents($this->tmp.'/docker/Dockerfile');
        $this->assertStringContainsString('docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype', $dockerfile);
        $this->assertStringContainsString("docker-php-ext-install \\\n    gd \\\n", $dockerfile);
    }

    public function test_mysql_has_time_to_create_its_first_database(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        // A fresh MySQL takes longer to start than its health check allows, so the
        // app containers would refuse to start behind it.
        $compose = (string) file_get_contents($this->tmp.'/docker-compose.yml');
        $mysql = substr($compose, (int) strpos($compose, "\n    mysql:\n"), 1200);
        $this->assertStringContainsString('start_period: 60s', $mysql);
    }

    public function test_backing_services_are_only_reachable_from_this_machine(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        // MySQL, Redis and Mailpit run with default credentials; on 0.0.0.0 anyone on
        // the network could reach them. The app's own ports stay open for other devices.
        $compose = (string) file_get_contents($this->tmp.'/docker-compose.yml');
        foreach (['FORWARD_DB_PORT', 'FORWARD_REDIS_PORT', 'FORWARD_MAILPIT_PORT', 'FORWARD_MAILPIT_DASHBOARD_PORT'] as $port) {
            $this->assertStringContainsString("'127.0.0.1:\${{$port}:-", $compose, $port);
        }
        $this->assertStringContainsString("'\${APP_PORT:-80}:80'", $compose);
    }

    public function test_pages_are_server_rendered_by_their_own_service(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        // Without a renderer, pages reach the browser empty and only render there.
        $compose = (string) file_get_contents($this->tmp.'/docker-compose.yml');
        $ssr = substr($compose, (int) strpos($compose, "\n    ssr:\n"), 600);
        $this->assertStringContainsString('command: node bootstrap/ssr/app.js', $ssr);
        $app = substr($compose, (int) strpos($compose, "\n    app:\n"), (int) strpos($compose, "\n    nginx:\n"));
        $this->assertStringContainsString('INERTIA_SSR_URL=http://ssr:13714', $app);
    }

    public function test_a_script_the_application_serves_itself_reaches_it(): void
    {
        // Livewire serves its script from a route. Answering every missing .js with a 404
        // leaves the admin panel blank.
        foreach (['nginx.conf', 'nginx-no-ssl.conf'] as $file) {
            $this->assertMatchesRegularExpression(
                '/ttf\|eot\)\$ \{[^}]*try_files \$uri \/index\.php\?\$query_string;/',
                (string) file_get_contents(dirname(__DIR__, 2).'/stubs/docker/docker/'.$file),
                $file,
            );
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

    public function test_a_skipped_file_does_not_stop_the_rest_from_publishing(): void
    {
        file_put_contents($this->tmp.'/docker-compose.yml', 'mine');

        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        foreach (['docker/Dockerfile', 'docker/nginx.conf', 'docker/php.ini', 'docker/xdebug.ini'] as $file) {
            $this->assertFileExists($this->tmp.'/'.$file);
        }
    }

    public function test_without_the_ssl_option_the_ssl_nginx_config_is_kept(): void
    {
        $this->artisan("docker:publish --path={$this->tmp}")->assertSuccessful();

        $this->assertSame(
            file_get_contents(dirname(__DIR__, 2).'/stubs/docker/docker/nginx.conf'),
            file_get_contents($this->tmp.'/docker/nginx.conf'),
        );
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

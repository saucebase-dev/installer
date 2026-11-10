<?php

namespace Saucebase\Installer\Console\Commands;

use Illuminate\Console\Command;

class PublishDockerCommand extends Command
{
    protected $signature = 'docker:publish
                            {--path= : The Saucebase application directory (defaults to the current directory)}
                            {--ssl= : Publish the SSL or the plain-HTTP nginx config (yes/no, default yes)}
                            {--force : Overwrite existing files without asking}';

    protected $description = 'Publish the Saucebase Docker files (docker-compose.yml, Dockerfile, nginx/php config) into an application';

    private const FILES = [
        'docker-compose.yml',
        'docker/Dockerfile',
        'docker/nginx.conf',
        'docker/php.ini',
        'docker/xdebug.ini',
    ];

    public function handle(): int
    {
        $stubs = dirname(__DIR__, 3).'/stubs/docker';
        $base = $this->option('path') ?: getcwd();
        $force = (bool) $this->option('force');

        foreach (self::FILES as $file) {
            $destination = $base.'/'.$file;

            if (file_exists($destination) && ! $force && ! $this->confirm("{$file} already exists. Overwrite it?", false)) {
                $this->line("  Skipped {$file}");

                continue;
            }

            @mkdir(dirname($destination), 0755, true);

            if (! copy($stubs.'/'.$file, $destination)) {
                $this->warn("Failed to publish {$file}.");

                continue;
            }

            $this->line("  Published {$file}");
        }

        $ssl = $this->option('ssl');

        if ($ssl !== null && $ssl !== '' && ! filter_var($ssl, FILTER_VALIDATE_BOOLEAN)) {
            if (! copy($stubs.'/docker/nginx-no-ssl.conf', $base.'/docker/nginx.conf')) {
                $this->warn('Failed to write nginx.conf (no-SSL). Check that Docker stubs were published first.');
            }
        }

        return self::SUCCESS;
    }
}

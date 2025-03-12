<?php

namespace elnurvl\DemoSailPlugin;

use elnurvl\DemoSailPlugin\Console\Concerns\InteractsWithNginx;
use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sail\Sail;
use Symfony\Component\Yaml\Yaml;

class ServiceProvider extends BaseServiceProvider
{
    use InteractsWithNginx;

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sail.php', 'sail');
    }

    public function boot()
    {
        if (config('sail.enabled')) {
            $appUrl = config('app.url');
            Sail::setBaseTemplate(__DIR__ . '/../stubs/docker-compose.stub')
                ->addPreInstallCallback(function (Command $command, array $services, string $appService) use ($appUrl) {
                    exec('npm install');

                    $composePath = base_path('docker-compose.yml');

                    if (file_exists($composePath)) {
                        $yaml = str_replace('\'{{EXTERNAL_NETWORK}}\'', config('sail.external_network'), file_get_contents($composePath));
                        $yaml = str_replace('{{CERT_PATH}}', './storage/app/certs', $yaml);
                        $yaml = str_replace('{{CA_PATH}}', config('sail.ca_path'), $yaml);
                        $yaml = str_replace('{{DOMAIN}}', Str::after($appUrl, '://'), $yaml);
                        file_put_contents(base_path('docker-compose.yml'), $yaml);
                    }
                    if (file_exists(base_path('nginx-site.conf'))) {
                        return;
                    }

                    // Generate nginx configuration
                    if ($command->option('no-interaction')) {
                        // Default Nginx config for non-interactive mode
                        $nginxConfig = [
                            'domain' => 'localhost',
                            'proxies' => [
                                'proxy_0' => [
                                    'path' => '/',
                                    'url' => 'http://host.docker.internal:3000',
                                    'name' => 'Web App',
                                ],
                                'mail' => [
                                    'path' => '/mail',
                                    'url' => 'http://mailpit:8025',
                                    'name' => 'Mailpit',
                                ],
                            ],
                            'cors_pattern' => 'https?:\/\/.*\.localhost',
                        ];
                    } else {
                        $nginxConfig = $this->gatherNginxConfigInteractively($command, $services);
                    }

                    $this->buildNginxConfig($command, $nginxConfig, $appService);

                    $command->info('Nginx configuration installed successfully. Place it in your Nginx sites directory.');
                    $command->getOutput()->writeln('<fg=gray>➜</> Generated at: <options=bold>' . base_path('nginx-site.conf') . '</>');

                    $command->getOutput()->writeln('');
                })->addNetwork([config('sail.external_network') => ['external' => true]]);
        }
    }
}
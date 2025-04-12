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
                ->addService('keycloak', __DIR__ . '/../stubs/keycloak.stub')
                ->addService('reverb', __DIR__ . '/../stubs/reverb.stub', preInstallCallback: function (Command $command) use ($appUrl) {
                    $command->call('install:broadcasting');
                    $environment = file_get_contents($this->app->basePath('.env'));

                    $environment = Str::replace('REVERB_HOST="localhost"', 'REVERB_HOST='.Str::after($appUrl,'://'), $environment);

                    file_put_contents($this->app->basePath('.env'), $environment);

                    $file = base_path('config/reverb.php');
                    $content = file_get_contents($file);

                    if (Str::startsWith($appUrl, 'https://')) {
                        $pattern = "/('tls'\s*=>\s*\[)([^\]]*?)(\h*])([,]?)/ms";

                        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                            $start = $matches[0][1];
                            $lineStart = strrpos(substr($content, 0, $start), "\n") + 1;
                            $line = substr($content, $lineStart, $start - $lineStart);
                            $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)) + 4);
                            $baseIndent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));

                            $newBlock = $matches[1][0] . "\n" .
                                $indent . "'local_cert'  => storage_path('/app/certs/server.crt'),\n" .
                                $indent . "'local_pk'    => storage_path('/app/certs/server.key'),\n" .
                                $indent . "'verify_peer' => false,\n" .
                                $baseIndent . "]";

                            $newContent = preg_replace($pattern, $newBlock, $content, 1);

                            if ($newContent !== null && $newContent !== $content) {
                                file_put_contents($file, $newContent);
                            }
                        }
                    } else {
                        $pattern = "/'tls'\s*=>\s*\[[^\]]*?\]/ms";
                        $newBlock = "'tls' => [],";
                        $newContent = preg_replace($pattern, $newBlock, $content, 1);

                        if ($newContent !== null && $newContent !== $content) {
                            file_put_contents($file, $newContent);
                        }
                    }
                })
                ->addPreInstallCallback(function (Command $command, array $services, string $appService) use ($appUrl) {
                    exec('npm install');

                    $composePath = base_path('docker-compose.yml');

                    $compose = Yaml::parseFile($composePath);

                    $services = array_merge($services, array_keys($compose['services']));

                    $domain = Str::after($appUrl, '://');

                    if (file_exists($composePath)) {
                        $yaml = str_replace('\'{{EXTERNAL_NETWORK}}\'', config('sail.external_network'), file_get_contents($composePath));
                        $yaml = str_replace('{{CERT_PATH}}', './storage/app/certs', $yaml);
                        $yaml = str_replace('{{CA_PATH}}', config('sail.ca_path'), $yaml);
                        $yaml = str_replace('{{DOMAIN}}', $domain, $yaml);
                        file_put_contents(base_path('docker-compose.yml'), $yaml);
                    }

                    $file = 'vite.config.js';
                    $content = file_get_contents(base_path($file));

// Pattern to match after the plugins array, before the closing })
                    $pattern = "/(plugins:\s*\[.*?\])(,\s*|\s*)\}/s";

// New server config to insert
                    $serverConfig = ",\n    server: {\n" .
                        "        https: {\n" .
                        "            key: fs.readFileSync((process.env.TLS_DIR || './storage/app/certs') + '/server.key'),\n" .
                        "            cert: fs.readFileSync((process.env.TLS_DIR || './storage/app/certs') + '/server.crt'),\n" .
                        "        },\n" .
                        "        hmr: {\n" .
                        "            host: '$domain',\n" .
                        "        }\n" .
                        "    }";

// Insert server config before the closing }
                    $newContent = preg_replace($pattern, "$1$serverConfig$2}", $content, 1);

                    if ($newContent !== null && $newContent !== $content) {
                        file_put_contents($file, $newContent);
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
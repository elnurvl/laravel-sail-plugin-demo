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

                    $this->configureReverbTls();
                })
                ->addPreInstallCallback(function (Command $command, array $services, string $appService) use ($appUrl) {
                    exec('npm install');

                    $this->configureDockerCompose();

                    $this->configureVite();

                    if (!file_exists(base_path('nginx-site.conf'))) {
                        $compose = Yaml::parseFile(base_path('docker-compose.yml'));
                        $services = array_merge($services, array_keys($compose['services']));
                        $this->configureNginx($command, $services, $appService);
                    }

                })->addNetwork([config('sail.external_network') => ['external' => true]]);
        }
    }

    private function configureReverbTls(): void
    {
        $file = base_path('config/reverb.php');
        $content = file_get_contents($file);
        $appUrl = config('app.url');
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
    }

    public function configureDockerCompose()
    {
        $composePath = base_path('docker-compose.yml');

        $appUrl = config('app.url');
        $domain = Str::after($appUrl, '://');

        if (file_exists($composePath)) {
            $yaml = str_replace('\'{{EXTERNAL_NETWORK}}\'', config('sail.external_network'), file_get_contents($composePath));
            $yaml = str_replace('{{CERT_PATH}}', './storage/app/certs', $yaml);
            $yaml = str_replace('{{CA_PATH}}', config('sail.ca_path'), $yaml);
            $yaml = str_replace('{{DOMAIN}}', $domain, $yaml);
            file_put_contents(base_path('docker-compose.yml'), $yaml);
        }
    }

    private function configureVite(): void
    {
        $file = 'vite.config.js';
        $content = file_get_contents(base_path($file));

        $appUrl = config('app.url');
        $domain = Str::after($appUrl, '://');

        $pattern = '/(plugins:\s*\[.*?\])(,\s*|\s*)\}/s';

        $serverConfig = ",\n    server: {\n" .
            "        https: {\n" .
            "            key: fs.readFileSync((process.env.TLS_DIR || './storage/app/certs') + '/server.key'),\n" .
            "            cert: fs.readFileSync((process.env.TLS_DIR || './storage/app/certs') + '/server.crt'),\n" .
            "        },\n" .
            "        hmr: {\n" .
            "            host: '$domain',\n" .
            "        }\n" .
            "    }";

        $newContent = preg_replace($pattern, "$1$serverConfig$2}", $content, 1);

        if ($newContent !== null && $newContent !== $content) {
            file_put_contents($file, $newContent);
        }
    }

    private function configureNginx(Command $command, array $services, string $appService): void
    {
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
    }
}
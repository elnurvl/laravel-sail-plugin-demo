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
            $domain = Str::after($appUrl, '://');
            Sail::setBaseTemplate(__DIR__ . '/../stubs/docker-compose.stub')
                ->addService('keycloak', __DIR__ . '/../stubs/keycloak.stub')
                ->addService('reverb', __DIR__ . '/../stubs/reverb.stub', preInstallCallback: function (Command $command) use ($appUrl) {
                    $command->call('install:broadcasting');

                    $environment = file_get_contents($this->app->basePath('.env'));
                    $environment = Str::replace('REVERB_HOST="localhost"', 'REVERB_HOST='.Str::after($appUrl,'://'), $environment);
                    file_put_contents($this->app->basePath('.env'), $environment);
                })
                ->addService('mailpit', __DIR__ . '/../stubs/mailpit.stub', isPersistent: true, env: [
                    'MAIL_MAILER' => 'smtp',
                    'MAIL_HOST' => 'mailpit',
                    'MAIL_PORT' => 587,
                    'MAIL_USERNAME' => 'sail',
                    'MAIL_PASSWORD' => 'password',
                    'MAIL_FROM_ADDRESS' => 'hello@'.$domain,
                ])
                ->addPreInstallCallback(function (Command $command, array $services, string $appService) use ($appUrl, $domain) {
                    exec('npm install');

                    $this->configureDockerCompose();

                    $this->configureVite();

                    if (Str::startsWith($appUrl, 'https://')) {
                        $this->generateTlsCertificates($command, $domain);
                    }

                    if (!file_exists(base_path('nginx-site.conf'))) {
                        $compose = Yaml::parseFile(base_path('docker-compose.yml'));
                        $services = array_merge($services, array_keys($compose['services']));
                        $this->configureNginx($command, $services, $appService);
                    }

                })->addNetwork([config('sail.external_network') => ['external' => true]]);
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

        $this->buildNginxConfig($command, $nginxConfig, $appService, $services);

        $command->info('Nginx configuration installed successfully. Place it in your Nginx sites directory.');
        $command->getOutput()->writeln('<fg=gray>➜</> Generated at: <options=bold>' . base_path('nginx-site.conf') . '</>');

        $command->getOutput()->writeln('');
    }

    /**
     * Generate TLS certificates (CA, key, and cert) for the given domain with SANs.
     *
     * @param Command $command
     * @param string $domain
     * @return void
     */
    protected function generateTlsCertificates(Command $command, string $domain): void
    {
        $sslDir = storage_path('app/certs');
        $caDir = rtrim(str_replace('~', getenv('HOME'), config('sail.ca_path')), '/');
        $caKey = "$caDir/ca.key";
        $caCert = "$caDir/ca.pem";
        $serverKey = "$sslDir/server.key";
        $serverCsr = "$sslDir/server.csr";
        $serverCert = "$sslDir/server.crt";
        $configFile = "$sslDir/openssl.cnf";

        if (!is_dir($sslDir)) {
            mkdir($sslDir, 0755, true);
        }

        if (!is_dir($caDir) && !mkdir($caDir, 0755, true) && !is_dir($caDir)) {
            $command->error("Failed to create CA directory at: $caDir");
            exit(1);
        }

        if (file_exists($serverCert) && file_exists($serverKey)) {
            return;
        }

        if (!file_exists($caKey) || !file_exists($caCert)) {
            exec("openssl genrsa -out '$caKey' 2048 2>/dev/null");
            $subject = '/CN=Laravel Sail CA Self Signed CN/O=Laravel Sail CA Self Signed Organization/OU=Developers/emailAddress=rootcertificate@laravel.sail';
            exec("openssl req -x509 -new -nodes -key '$caKey' -sha256 -days 3650 -out '$caCert' -subj '$subject' 2>/dev/null");
            $command->getOutput()->info("Certificate authority has been generated and needs to be added to system truststore.");
            switch (PHP_OS_FAMILY) {
                case 'Darwin':
                    exec("sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain '$caCert'");
                    break;
                case 'Windows':
                    exec("certutil -addstore -f Root \"$caCert\"");
                    break;
                case 'Linux':
                    $distro = strtolower(trim(exec('grep ^ID= /etc/os-release | cut -d= -f2 | tr -d \'"\' ')));
                    if (in_array($distro, ['ubuntu', 'debian'])) {
                        $dest = "/usr/local/share/ca-certificates/laravel_sail_ca.crt";
                        exec("sudo cp '$caCert' '$dest'");
                        exec("sudo update-ca-certificates");
                    } elseif (in_array($distro, ['centos', 'fedora', 'rhel', 'rocky', 'almalinux'])) {
                        $dest = "/etc/pki/ca-trust/source/anchors/laravel_sail_ca.crt";
                        exec("sudo cp '$caCert' '$dest'");
                        exec("sudo update-ca-trust extract");
                    }
                    break;
            }
        }

        exec("openssl genrsa -out '$serverKey' 2048 2>/dev/null");

        $extraDomains = [];
        if ($extra = config('sail.tls_san')) {
            $extraDomains = array_map(fn($line) => "DNS:" . trim($line), explode(',', $extra));
        }

        $sanString = implode(", ", array_merge([
            "DNS:$domain",
            "DNS:*.$domain",
            "DNS:localhost",
            "DNS:mailpit",
            "DNS:keycloak",
        ], $extraDomains));

        $configContent = <<<EOT
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = $domain

[v3_req]
keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment
extendedKeyUsage = serverAuth
subjectAltName = $sanString
EOT;

        file_put_contents($configFile, $configContent);

        exec("openssl req -new -key '$serverKey' -out '$serverCsr' -config '$configFile' 2>/dev/null");
        exec("openssl x509 -req -in '$serverCsr' -CA '$caCert' -CAkey '$caKey' -CAcreateserial -out '$serverCert' -days 365 -sha256 -extfile '$configFile' -extensions v3_req 2>/dev/null");

        $command->info("Generated TLS certificates.");

        @unlink($serverCsr);
        @unlink($configFile);
    }
}
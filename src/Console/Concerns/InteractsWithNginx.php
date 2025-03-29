<?php

namespace elnurvl\DemoSailPlugin\Console\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

trait InteractsWithNginx
{
    /**
     * The default domain to use if none is provided.
     *
     * @var string
     */
    protected $defaultDomain = 'localhost';

    /**
     * Gather Nginx configuration details interactively.
     *
     * @return array
     */
    protected function gatherNginxConfigInteractively(Command $command, array $services)
    {
        $appUrl = config('app.url');
        $isHttps = Str::startsWith('https://', $appUrl);
        $domain = $command->ask('What is the domain?', Str::after($appUrl, '://'));
        $corsPattern = "https?:\/\/.*\\." . preg_quote(parse_url('http://' . $domain, PHP_URL_HOST), '/');

        $proxies = [];

        // Generic proxy loop
        while ($command->confirm('Do you want to add a proxy?', false)) {
            $path = $command->ask('What is the path?', '/');
            $url = $command->ask('What is the URL?', 'http://host.docker.internal:3000');
            $name = $command->ask('What is the name?', 'Web App');

            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'http://' . $url;
            }

            $proxies["proxy_" . count($proxies)] = [
                'path' => Str::start($path, '/'),
                'url' => $url,
                'name' => $name,
            ];
        }

        $isApi = !empty($proxies);

        // Keycloak proxy
        if (in_array('keycloak', $services) && $command->confirm('Do you want to add Keycloak proxy?', true)) {
            $proxies['keycloak'] = [
                'path' => Str::start($command->ask('What is the path?', env('KEYCLOAK_PATH', 'auth')), '/'),
                'url' => $command->ask('What is the URL?', 'https://keycloak:'.env('FORWARD_KEYCLOAK_PORT', '8443')),
                'name' => 'Keycloak',
            ];
        }

        // Mail proxy
        if (in_array('mailpit', $services) && $command->confirm('Do you want to add Mail proxy?', true)) {
            $proxies['mail'] = [
                'path' => Str::start($command->ask('What is the path?', '/mail'), '/'),
                'url' => $command->ask('What is the URL?', 'http://mailpit:'.env('FORWARD_MAILPIT_DASHBOARD_PORT', '8025')),
                'name' => 'Mailpit',
            ];
        }

        return [
            'domain' => $domain,
            'proxies' => $proxies,
            'cors_pattern' => $corsPattern,
        ];
    }

    /**
     * Build the Nginx configuration file.
     *
     * @param array $config
     * @return void
     */
    protected function buildNginxConfig(Command $command, array $config, string $appService)
    {
        $stubPath = __DIR__ . '/../../../stubs/nginx-site.stub';
        $outputPath = base_path('nginx-site.conf');

        // Load the stub
        $nginxConfig = file_get_contents($stubPath);

        $search = collect($config['proxies'])->search(fn($item) => $item['path'] === '/');
        if ($search !== false) {
            $proxyPattern = '/api';
        } else {
            $proxyPattern = '/';
        }

        // Replace basic placeholders
        $nginxConfig = str_replace('{{DOMAIN}}', $config['domain'], $nginxConfig);
        $nginxConfig = str_replace('{{CORS_PATTERN}}', $config['cors_pattern'], $nginxConfig);
        $nginxConfig = str_replace('{{FPM_PROXY_PATTERN}}', $proxyPattern, $nginxConfig);
        $nginxConfig = str_replace('{{APP_SERVICE}}', $appService, $nginxConfig);

        if (Str::startsWith(config('app.url'), 'https://')) {
            $this->generateTlsCertificates($command, $config['domain']);
        }

        // Handle proxy blocks
        $proxyBlocks = '';
        foreach ($config['proxies'] as $service => $details) {
            $proxyBlocks .= $this->generateProxyBlock($service, $details) . PHP_EOL;
        }

        // Insert proxy blocks into the configuration
        $nginxConfig = $this->insertProxyBlocks($nginxConfig, $proxyBlocks);

        // Write the final configuration file
        file_put_contents($outputPath, $nginxConfig);

        $command->info("Nginx configuration file generated at: {$outputPath}");
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

    /**
     * Generate a proxy block for a given service.
     *
     * @param string $service
     * @param array $details
     * @return string
     */
    protected function generateProxyBlock(string $service, array $details): string
    {
        $block = '';
        $serviceUpper = strtoupper($service);

        $block .= "    location {$details['path']} {\n";
        $block .= "        proxy_set_header Cache-Control \$http_cache_control;\n";
        $block .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $block .= "        proxy_set_header Host \$http_host;\n";
        $block .= "        proxy_redirect off;\n";
        $block .= "        set \$upstream {$details['url']};\n";
        $block .= "        proxy_pass \$upstream;\n";
        $block .= "        proxy_http_version 1.1;\n";
        $block .= "        proxy_set_header Upgrade \$http_upgrade;\n";
        $block .= "        proxy_set_header Connection \$connection_upgrade;\n";
        $block .= "        error_page 502 = @{$service}_502;\n";
        $block .= "    }\n";
        $block .= "    location @{$service}_502 {\n";
        $block .= "        default_type text/html;\n";
        $block .= "        return 502 \"\$error_template {$details['name']}\";\n";
        $block .= "    }\n";

        return $block;
    }

    /**
     * Insert proxy blocks into the Nginx configuration stub, before the last closing brace of the server block.
     *
     * @param string $nginxConfig
     * @param string $proxyBlocks
     * @return string
     */
    protected function insertProxyBlocks(string $nginxConfig, string $proxyBlocks): string
    {
        $lines = explode(PHP_EOL, $nginxConfig);
        $insertPosition = -1;

        // Find the last closing brace of the main server block
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === '}') {
                $insertPosition = $i;
                break;
            }
        }

        if ($insertPosition === -1) {
            // If no closing brace is found (unlikely), append at the end
            $lines[] = $proxyBlocks;
        } else {
            // Insert the proxy blocks before the closing brace
            array_splice($lines, $insertPosition, 0, $proxyBlocks);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Prepare the Nginx installation by validating dependencies.
     *
     * @return void
     */
    protected function prepareNginxInstallation()
    {
        $this->info('Preparing Nginx configuration...');
    }
}

<?php

namespace elnurvl\DemoSailPlugin\Console\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

trait InteractsWithNginx
{
    protected function gatherNginxConfigInteractively(Command $command, array $services)
    {
        $appUrl = config('app.url');
        $domain = Str::after($appUrl, '://');
        $corsPattern = "https?:\/\/.*\\." . preg_quote(parse_url('http://' . $domain, PHP_URL_HOST), '/');

        $proxies = [];

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

        if (in_array('keycloak', $services)) {
            $proxies['keycloak'] = [
                'path' => '/auth',
                'url' => 'https://keycloak:'.env('FORWARD_KEYCLOAK_PORT', '8443'),
                'name' => 'Keycloak',
            ];
        }

        if (in_array('mailpit', $services)) {
            $proxies['mail'] = [
                'path' => '/mail',
                'url' => 'http://mailpit:'.env('FORWARD_MAILPIT_DASHBOARD_PORT', '8025'),
                'name' => 'Mailpit',
            ];
        }

        return [
            'proxies' => $proxies,
            'cors_pattern' => $corsPattern,
        ];
    }

    /**
     * Build the Nginx configuration file.
     *
     * @param Command $command
     * @param array $config
     * @param string $appService
     * @param array $services
     * @return void
     */
    protected function buildNginxConfig(Command $command, array $config, string $appService, array $services)
    {
        $stubPath = __DIR__ . '/../../../stubs/nginx-site.stub';
        $outputPath = base_path('nginx-site.conf');

        $nginxConfig = file_get_contents($stubPath);

        $search = collect($config['proxies'])->search(fn($item) => $item['path'] === '/');
        if ($search !== false) {
            $proxyPattern = '~ ^/(api|apps|telescope|horizon|health|_ignition|vendor|.well-known)';
        } else {
            $proxyPattern = '/';
        }

        $domain = Str::after(config('app.url'), '://');

        $nginxConfig = str_replace('{{DOMAIN}}', $domain, $nginxConfig);
        $nginxConfig = str_replace('{{CORS_PATTERN}}', $config['cors_pattern'], $nginxConfig);
        $nginxConfig = str_replace('{{APP_SERVICE}}', $appService, $nginxConfig);
        $nginxConfig = str_replace('{{FPM_PROXY_PATTERN}}', $proxyPattern, $nginxConfig);

        $websocketBlock = in_array('reverb', $services) ? $this->generateWebSocketBlock() : '';
        $nginxConfig = str_replace('{{REVERB}}', $websocketBlock, $nginxConfig);

        $proxyBlocks = '';
        foreach ($config['proxies'] as $service => $details) {
            $proxyBlocks .= $this->generateProxyBlock($service, $details) . PHP_EOL;
        }

        $nginxConfig = $this->insertProxyBlocks($nginxConfig, $proxyBlocks);

        file_put_contents($outputPath, $nginxConfig);

        $command->info("Nginx configuration file generated at: {$outputPath}");
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
     * Generate the WebSocket block for Reverb.
     *
     * @return string
     */
    protected function generateWebSocketBlock(): string
    {
        return <<<EOT
location /app {
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header Scheme \$scheme;
        proxy_set_header SERVER_PORT \$server_port;
        proxy_set_header REMOTE_ADDR \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        set \$upstream http://reverb:8080;
        proxy_pass \$upstream;
    }
EOT;
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
}

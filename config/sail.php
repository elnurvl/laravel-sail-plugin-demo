<?php

return [
    'external_network' => env('SAIL_EXTERNAL_NETWORK', 'sail-external'),
    'ca_path' => env('SAIL_CA_PATH','~/.sail'),
    'tls_san' => env('SAIL_TLS_SAN'),
    'enabled' => env('SAIL_PLUGIN_ENABLED', true),
];
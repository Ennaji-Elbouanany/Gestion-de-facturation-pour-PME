<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5176',
        'http://192.168.110.162:5176',
        'http://localhost:5174',
        'http://192.168.110.162:5174',
        'http://127.0.0.1:5176'
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

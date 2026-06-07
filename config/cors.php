<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'supports_credentials' => true,
    'allowed_origins' => [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:8000',
    'http://127.0.0.1:8000',],
    'allowed_headers' => ['*'],
    'allowed_methods' => ['*'],
];

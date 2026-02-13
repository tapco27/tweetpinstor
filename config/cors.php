<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // ضع هنا دومين الأدمن (Vite غالباً 5173)
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // إذا تستخدم Sanctum SPA (كوكيز) خليه true
    'supports_credentials' => true,
];

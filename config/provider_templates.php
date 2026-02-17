<?php

/**
 * Provider Templates Registry
 *
 * Admin will create Provider Integrations based on one of these templates.
 *
 * Notes:
 * - This is intentionally config-based (not DB) to keep templates versioned in code.
 * - template_code is stored in provider_integrations.template_code.
 * - type is used by FulfillmentService to decide how to deliver.
 */

return [
    // Internal inventory provider (Digital Pins)
    'inventory' => [
        'code' => 'inventory',
        'type' => 'digital_pins',
        'names' => [
            'ar' => 'المخزون الرقمي',
            'en' => 'Digital Inventory',
            'tr' => 'Dijital Stok',
        ],
        'credential_fields' => [],
        'notes' => 'Internal stock-based fulfillment using digital_pins table.',
    ],

    // Example external providers (placeholders - adjust to your real providers)
    'free_kasa' => [
        'code' => 'free_kasa',
        'type' => 'api',
        'names' => [
            'ar' => 'Free Kasa',
            'en' => 'Free Kasa',
            'tr' => 'Free Kasa',
        ],
        'credential_fields' => [
            ['key' => 'username', 'label' => ['ar' => 'اسم المستخدم', 'en' => 'Username', 'tr' => 'Kullanıcı adı'], 'required' => true],
            ['key' => 'password', 'label' => ['ar' => 'كلمة المرور', 'en' => 'Password', 'tr' => 'Şifre'], 'required' => true],
        ],
        'notes' => 'External API provider (basic auth / token exchange depends on your integration service).',
    ],

    'bsv' => [
        'code' => 'bsv',
        'type' => 'api',
        'names' => [
            'ar' => 'BSV',
            'en' => 'BSV',
            'tr' => 'BSV',
        ],
        'credential_fields' => [
            ['key' => 'api_key', 'label' => ['ar' => 'API Key', 'en' => 'API Key', 'tr' => 'API Anahtarı'], 'required' => true],
        ],
        'notes' => 'External API provider (api key).',
    ],

    /**
     * Tweet-Pin Provider (Real)
     * Base URL: https://api.tweet-pin.com/
     * Header: api-token: 18792ee1daf127772d660a49df6a11546b4e833c19119331
     */
    'tweet_pin' => [
        'code' => 'tweet_pin',
        // Custom provider driver handled in FulfillmentService
        'type' => 'tweet_pin',
        'names' => [
            'ar' => 'Tweet-Pin',
            'en' => 'Tweet-Pin',
            'tr' => 'Tweet-Pin',
        ],
        'credential_fields' => [
            ['key' => 'api_token', 'label' => ['ar' => 'Api Token', 'en' => 'API Token', 'tr' => 'API Token'], 'required' => true],
            // Optional override if you use a proxy or staging base URL
            ['key' => 'base_url', 'label' => ['ar' => 'Base URL (اختياري)', 'en' => 'Base URL (optional)', 'tr' => 'Base URL (opsiyonel)'], 'required' => false],
        ],
        'notes' => 'Tweet-Pin API integration (profile/products/newOrder/check). Uses order_uuid for idempotency.',
    ],
];

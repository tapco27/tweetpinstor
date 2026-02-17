<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'enabled' => env('STRIPE_ENABLED', false),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Social login
    'google' => [
        // Comma-separated list in env: GOOGLE_OAUTH_CLIENT_IDS
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_OAUTH_CLIENT_IDS', ''))
        ))),
    ],

    'apple' => [
        // Comma-separated list in env: APPLE_CLIENT_IDS
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],

    'fulfillment' => [
  'base_url' => env('FULFILLMENT_BASE_URL'),
  'token' => env('FULFILLMENT_API_TOKEN'),
  'timeout' => env('FULFILLMENT_TIMEOUT', 15),
  'retries' => env('FULFILLMENT_RETRIES', 1),
  ],

    // Tweet-Pin (Real provider). Usually credentials are stored per ProviderIntegration,
    // but these act as safe defaults.
    'tweet_pin' => [
        'base_url' => env('TWEETPIN_BASE_URL', 'https://api.tweet-pin.com'),
        'timeout' => env('TWEETPIN_TIMEOUT', 20),
        'retries' => env('TWEETPIN_RETRIES', 1),
    ],

];

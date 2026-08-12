<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    // Apple has no static client secret: it is a short-lived ES256 JWT signed
    // with the .p8 key, which socialiteproviders/apple mints per request from
    // team_id + key_id + private_key. So client_secret stays empty and there
    // is nothing to rotate.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => '',
        'redirect' => env('APPLE_REDIRECT_URI'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        // Absolute path to the .p8, or the key itself as plain text.
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'pop3' => [
        'host' => env('POP3_HOST'),
        'port' => env('POP3_PORT', 995),
        'encryption' => env('POP3_ENCRYPTION', 'ssl'),
        'username' => env('POP3_USERNAME'),
        'password' => env('POP3_PASSWORD'),
        'validate_cert' => env('POP3_VALIDATE_CERT', true),
    ],

    // Free public OSRM demo server by default — fine for the one-time
    // namibway:backfill-city-driving-hours batch run this powers (not called
    // live per Kaia request). Override with a self-hosted instance's URL if
    // the public demo's rate/size limits ever become a problem.
    'osrm' => [
        'base_url' => env('OSRM_BASE_URL', 'https://router.project-osrm.org'),
    ],

];

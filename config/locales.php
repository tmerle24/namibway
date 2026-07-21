<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales NamibWay's content and UI can be displayed in. The app
    | ships English-only content for the MVP, but both the Listing model
    | (spatie/laravel-translatable) and the frontend (vue-i18n) are wired
    | for all of these from day one — see CLAUDE.md.
    |
    */

    'supported' => ['en', 'de', 'nl', 'fr', 'es'],

    'labels' => [
        'en' => 'English',
        'de' => 'Deutsch',
        'nl' => 'Nederlands',
        'fr' => 'Français',
        'es' => 'Español',
    ],
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Display Currencies
    |--------------------------------------------------------------------------
    |
    | All prices are stored and processed internally in NAD (Listing.price_from,
    | itinerary totals, bookings) — this only controls what currency prices are
    | CONVERTED TO for display. NAD is pegged 1:1 to ZAR (Common Monetary Area),
    | so NAD amounts are converted using the ZAR exchange rate directly.
    |
    */

    'supported' => ['NAD', 'USD', 'EUR', 'ZAR', 'GBP'],

    'default' => 'NAD',

    'symbols' => [
        'NAD' => 'N$',
        'USD' => '$',
        'EUR' => '€',
        'ZAR' => 'R',
        'GBP' => '£',
    ],

    'labels' => [
        'NAD' => 'NAD (N$)',
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
        'ZAR' => 'ZAR (R)',
        'GBP' => 'GBP (£)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale-Based Currency Guess
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\Currency\CurrencyLocator to pick a sensible default
    | display currency for visitors who haven't set one explicitly (no saved
    | profile preference, no cookie yet). This is a heuristic based on the
    | Accept-Language header's region subtag (e.g. "en-NA") and, failing that,
    | the resolved UI language — NOT real geolocation. Rule of thumb: Europe
    | -> EUR, Namibia/Africa -> NAD, everything else -> USD.
    |
    */

    // ISO 3166-1 alpha-2 codes mapped to NAD (Namibia + the rest of Africa).
    'african_regions' => [
        'NA', 'ZA', 'BW', 'ZM', 'ZW', 'AO', 'MZ', 'LS', 'SZ', 'MW', 'TZ', 'KE', 'UG', 'RW', 'BI',
        'CD', 'CG', 'GA', 'CM', 'NG', 'GH', 'CI', 'SN', 'ML', 'BF', 'NE', 'TD', 'ET', 'SO', 'DJ',
        'ER', 'SD', 'SS', 'EG', 'LY', 'TN', 'DZ', 'MA', 'MR', 'GM', 'GN', 'GW', 'SL', 'LR', 'TG',
        'BJ', 'GQ', 'CF', 'MG', 'MU', 'SC', 'KM', 'CV', 'ST',
    ],

    // ISO 3166-1 alpha-2 codes mapped to GBP.
    'gbp_regions' => ['GB'],

    // ISO 3166-1 alpha-2 codes mapped to EUR (EU/EEA + a few close neighbours).
    'eu_regions' => [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'NO', 'IS', 'LI',
    ],

    // UI language -> currency, used when Accept-Language carries no region subtag.
    // "en" is deliberately absent — it's spoken far too broadly to imply a region.
    'language_fallback' => [
        'de' => 'EUR',
        'nl' => 'EUR',
        'fr' => 'EUR',
        'es' => 'EUR',
    ],

    // Final catch-all when nothing above matched.
    'guess_fallback' => 'USD',
];

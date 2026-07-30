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
];

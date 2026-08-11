<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operating Countries
    |--------------------------------------------------------------------------
    |
    | NamibWay operates in Namibia today, but the same codebase is meant to
    | expand into South Africa and other Southern African markets later (see
    | CLAUDE.md). Keep country-specific formatting like phone dial codes and
    | trunk prefixes here instead of hardcoding "+264" in views/controllers,
    | so adding a country later is a config change, not a code change.
    |
    | A listing resolves its country through its city's region
    | (listings.city_id -> cities.region_id -> regions.country_code), which is
    | what App\Support\CountrySettings does. 'default_country' is the fallback
    | for anything that has no region to ask — a listing with no city, or a
    | phone number that isn't already written in international "+..." form.
    |
    */

    'default_country' => 'NA',

    'countries' => [
        'NA' => [
            'name' => 'Namibia',
            'dial_code' => '+264',
            // Local numbers are dialled with a leading trunk "0" (e.g. "061
            // 244 535") that must be dropped before adding the dial code.
            'trunk_prefix' => '0',

            // ISO 4217. This is the country's own currency, not a display
            // preference — what a lodge charges in. Display conversion is a
            // separate concern and lives in config/currencies.php.
            'currency' => 'NAD',

            // A booking system's "today" is the property's date, not the
            // server's: who arrives today, who is a no-show, which night an
            // occupancy figure describes.
            'timezone' => 'Africa/Windhoek',

            // VAT. Recorded so the resolution path exists and is
            // country-parameterised; nothing computes tax yet, because
            // nothing collects payment yet (Stripe is Phase 2).
            'tax' => [
                'name' => 'VAT',
                'rate' => 0.15,
                // Namibia also levies a 1% Tourism Levy on accommodation,
                // reported to the NTB. It is a separate line from VAT, which
                // is why it is not folded into the rate above.
                'tourism_levy_rate' => 0.01,
            ],

            // Default cancellation terms, overridable per property later.
            // 'penalty_days' is the window before arrival inside which a
            // cancellation counts as late (StayStatus::CancelledLate).
            'cancellation' => [
                'penalty_days' => 7,
            ],
        ],
        'ZA' => [
            'name' => 'South Africa',
            'dial_code' => '+27',
            'trunk_prefix' => '0',
            'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
            'tax' => [
                'name' => 'VAT',
                'rate' => 0.15,
                'tourism_levy_rate' => 0.01,
            ],
            'cancellation' => [
                'penalty_days' => 7,
            ],
        ],
    ],
];

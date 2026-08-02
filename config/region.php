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
    | Listings don't carry their own country field yet, so 'default_country'
    | is used for any number that isn't already written in international
    | "+..." form.
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
        ],
        'ZA' => [
            'name' => 'South Africa',
            'dial_code' => '+27',
            'trunk_prefix' => '0',
        ],
    ],
];

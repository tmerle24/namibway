<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kaia — the NamibWay AI travel companion
    |--------------------------------------------------------------------------
    |
    | Both phases use Haiku. The itinerary call used to be Sonnet with a
    | multi-round search_listings tool loop (2-3 sequential API round-trips,
    | 10-60s total) — the whole catalog is now fetched deterministically in
    | PHP and handed to Claude in one single-shot, forced-tool-call request,
    | so a fast model is both sufficient (no tool-orchestration reasoning
    | left to do, just fill in structured JSON from the given data) and the
    | main lever left for cutting latency, since round-trip count is fixed at 1.
    |
    */

    'interview_model' => env('KAIA_INTERVIEW_MODEL', 'claude-haiku-4-5-20251001'),

    'itinerary_model' => env('KAIA_ITINERARY_MODEL', 'claude-haiku-4-5-20251001'),

    'interview_max_tokens' => (int) env('KAIA_INTERVIEW_MAX_TOKENS', 1024),

    // A 2-variant, multi-day structured plan is a lot of JSON — a 14-night
    // trip alone is ~28 day entries, which overflowed a 4096 budget in testing.
    'itinerary_max_tokens' => (int) env('KAIA_ITINERARY_MAX_TOKENS', 8192),

    // Namibia's 14 political regions used in the Listing.region column —
    // Claude naturally thinks in tourist-area names (Etosha, Sossusvlei...),
    // so the ones we actually seed data for need to be spelled out.
    'regions' => [
        'Khomas', 'Erongo', 'Hardap', 'Kunene', 'Otjozondjupa', 'Karas',
        'Ohangwena', 'Omusati', 'Oshana', 'Oshikoto', 'Kavango East',
        'Kavango West', 'Zambezi', 'Omaheke',
    ],
];

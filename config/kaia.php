<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kaia — the NamibWay AI travel companion
    |--------------------------------------------------------------------------
    |
    | Per CLAUDE.md: Haiku for the conversational interview turns (fast,
    | cheap), Sonnet for the grounded itinerary generation (needs to reason
    | over real listing data via tool-calling).
    |
    */

    'interview_model' => env('KAIA_INTERVIEW_MODEL', 'claude-haiku-4-5-20251001'),

    'itinerary_model' => env('KAIA_ITINERARY_MODEL', 'claude-sonnet-5'),

    'interview_max_tokens' => (int) env('KAIA_INTERVIEW_MAX_TOKENS', 1024),

    // Generous headroom: the model "thinks" before calling propose_itinerary,
    // and a 2-variant, multi-day structured plan is a lot of JSON — a 14-night
    // trip alone is ~28 day entries, which overflowed a 4096 budget in testing.
    'itinerary_max_tokens' => (int) env('KAIA_ITINERARY_MAX_TOKENS', 8192),

    // Namibia's 14 political regions used in the Listing.region column —
    // Claude naturally thinks in tourist-area names (Etosha, Sossusvlei...),
    // so the ones we actually seed data for need to be spelled out.
    'regions' => ['Khomas', 'Erongo', 'Hardap', 'Kunene', 'Otjozondjupa', 'Karas'],
];

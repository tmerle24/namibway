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

    /*
    |--------------------------------------------------------------------------
    | Rate limits on the two endpoints that spend money
    |--------------------------------------------------------------------------
    |
    | Two different jobs, deliberately kept apart. `per_conversation` paces one
    | traveler's chat and is keyed on the session, because a conversation is
    | what a session is. `per_address` is a burst brake keyed on the IP, and it
    | is only a backstop: a session cookie is free to mint, so the per-session
    | limit alone would stop nothing.
    |
    | Keying the traveler-facing limit on the address was the bug. A phone here
    | is behind a mobile carrier's NAT, a lodge is one line for every guest and
    | an operator's office is one line for the whole team — so 20 a minute was
    | shared between strangers, and the second person to open the chat was told
    | they had sent "a lot of messages" on their first one.
    |
    | Both are in env so an incident can be handled without a deploy.
    |
    | One thing to remember before putting a CDN or a load balancer in front of
    | the app: there are no trusted proxies configured, so the address the
    | limiter sees would become the proxy's, and `per_address` would be one
    | bucket for the whole internet. Configure trusted proxies in the same
    | change, or this becomes an outage rather than a limit.
    |
    */

    'rate_limit' => [
        'per_conversation' => (int) env('KAIA_RATE_LIMIT_PER_CONVERSATION', 20),

        'per_address' => (int) env('KAIA_RATE_LIMIT_PER_ADDRESS', 100),
    ],

    // Namibia's 14 political regions used in the Listing.region column —
    // Claude naturally thinks in tourist-area names (Etosha, Sossusvlei...),
    // so the ones we actually seed data for need to be spelled out.
    'regions' => [
        'Khomas', 'Erongo', 'Hardap', 'Kunene', 'Otjozondjupa', 'Karas',
        'Ohangwena', 'Omusati', 'Oshana', 'Oshikoto', 'Kavango East',
        'Kavango West', 'Zambezi', 'Omaheke',
    ],
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VisitNamibia data enrichment pipeline
    |--------------------------------------------------------------------------
    |
    | Both AI steps use Haiku — this is bulk structured extraction / short
    | copywriting over already-fetched page text, not conversational
    | reasoning, so the cheaper/faster model is sufficient (same reasoning
    | as Kaia's interview_model, see config/kaia.php).
    |
    */

    'extraction_model' => env('ENRICHMENT_EXTRACTION_MODEL', 'claude-haiku-4-5-20251001'),

    'description_model' => env('ENRICHMENT_DESCRIPTION_MODEL', 'claude-haiku-4-5-20251001'),

    'extraction_max_tokens' => (int) env('ENRICHMENT_EXTRACTION_MAX_TOKENS', 1024),

    'description_max_tokens' => (int) env('ENRICHMENT_DESCRIPTION_MAX_TOKENS', 768),

    // How many of the lowest-completion listings the nightly scheduler enriches per run.
    'nightly_batch_size' => (int) env('ENRICHMENT_NIGHTLY_BATCH_SIZE', 500),

    // On-demand trigger thresholds (Listing::isDueForEnrichment mirrors these).
    'score_threshold' => (int) env('ENRICHMENT_SCORE_THRESHOLD', 80),
    'refresh_days' => (int) env('ENRICHMENT_REFRESH_DAYS', 90),

    // Rough per-1K-token USD pricing, used only for the enrichment_jobs.cost_estimate
    // bookkeeping column shown in the dashboard — not billing-accurate.
    'pricing' => [
        'claude-haiku-4-5-20251001' => ['input' => 0.001, 'output' => 0.005],
    ],

    // Rough per-request USD estimates for the Google Places API calls the pipeline makes
    // (WebsiteFinderService, GooglePlacesPhotoFinder) — UNVERIFIED placeholders, not
    // billing-accurate. Google's Places pricing has multiple tiers/SKUs depending on
    // which fields you request and which plan your project is on; check
    // console.cloud.google.com/billing for your project's actual per-SKU cost and
    // override these via env if they differ meaningfully. Used only for the
    // enrichment_jobs.places_cost_estimate bookkeeping column.
    'places_pricing' => [
        'find_place' => (float) env('ENRICHMENT_PLACES_FIND_COST', 0.017),
        'place_details' => (float) env('ENRICHMENT_PLACES_DETAILS_COST', 0.020),
        'photo' => (float) env('ENRICHMENT_PLACES_PHOTO_COST', 0.007),
    ],

    // Circuit breakers (see EnrichmentBudgetGuard): once today's already-recorded
    // enrichment_jobs total for a category reaches its cap, every further call in
    // that category is skipped for the rest of the day instead of just continuing
    // to spend. Built after a redundant-lookup bug burned ~$840/~123,500 Places
    // calls in a single day with nothing to stop it. Set either to 0 to disable
    // that cap entirely. A one-off large backfill (e.g. re-enriching thousands of
    // listings at once) can legitimately want more than the default in a single
    // day — raise these temporarily via env rather than disabling them outright.
    'places_daily_budget_usd' => (float) env('ENRICHMENT_PLACES_DAILY_BUDGET_USD', 75),
    'ai_daily_budget_usd' => (float) env('ENRICHMENT_AI_DAILY_BUDGET_USD', 30),
];

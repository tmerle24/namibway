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
    'nightly_batch_size' => (int) env('ENRICHMENT_NIGHTLY_BATCH_SIZE', 200),

    // On-demand trigger thresholds (Listing::isDueForEnrichment mirrors these).
    'score_threshold' => (int) env('ENRICHMENT_SCORE_THRESHOLD', 80),
    'refresh_days' => (int) env('ENRICHMENT_REFRESH_DAYS', 90),

    // Rough per-1K-token USD pricing, used only for the enrichment_jobs.cost_estimate
    // bookkeeping column shown in the dashboard — not billing-accurate.
    'pricing' => [
        'claude-haiku-4-5-20251001' => ['input' => 0.001, 'output' => 0.005],
    ],
];

<?php

namespace App\Services\Enrichment;

use App\Models\EnrichmentJob;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Circuit breaker for enrichment spend — Google Places (GooglePlacesLookupService,
 * GooglePlacesPhotoFinder) and Claude (EnrichmentPipeline, gating ai_extract/
 * description) both check hasBudget() before making a billed API call. Once today's
 * already-recorded enrichment_jobs total for that category crosses its configured
 * daily cap, every further call in that category is skipped for the rest of the
 * calendar day instead of just continuing to spend.
 *
 * Built after a bug (three services independently re-querying the same Google
 * Places business on every enrichment run) burned ~$840 and ~123,500 Places calls
 * in a single day with nothing to stop it. Checked against completed jobs only,
 * not calls made so far by the currently-running job — worst case that undercounts
 * by one job's own in-flight spend (a few cents), which is fine for a breaker meant
 * to catch runaway spend across many jobs, not enforce exact-to-the-cent precision.
 */
class EnrichmentBudgetGuard
{
    /** @var array<string, bool> Memoized per instance (one per queue job) per category — one DB query per job per category, not per call. */
    private array $withinBudget = [];

    public function hasBudget(string $category): bool
    {
        if (array_key_exists($category, $this->withinBudget)) {
            return $this->withinBudget[$category];
        }

        [$column, $configKey] = match ($category) {
            'places' => ['places_cost_estimate', 'enrichment.places_daily_budget_usd'],
            'ai' => ['cost_estimate', 'enrichment.ai_daily_budget_usd'],
            default => throw new InvalidArgumentException("Unknown budget category: {$category}"),
        };

        $budget = (float) config($configKey);

        if ($budget <= 0) {
            return $this->withinBudget[$category] = true; // 0/negative = no cap configured
        }

        $spentToday = (float) EnrichmentJob::where('created_at', '>=', now()->startOfDay())->sum($column);

        $this->withinBudget[$category] = $spentToday < $budget;

        if (! $this->withinBudget[$category]) {
            Log::warning("EnrichmentBudgetGuard: daily {$category} budget exhausted, skipping further calls today", [
                'category' => $category,
                'spent_today' => $spentToday,
                'budget' => $budget,
            ]);
        }

        return $this->withinBudget[$category];
    }
}

<?php

namespace App\Services\Enrichment;

use App\Models\EnrichmentJob;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker for Google Places spend. GooglePlacesLookupService and
 * GooglePlacesPhotoFinder both check hasBudget() before making a real Places HTTP
 * call; once today's already-recorded enrichment_jobs.places_cost_estimate total
 * crosses enrichment.places_daily_budget_usd, every further Places call for the
 * rest of the calendar day is skipped — treated the same as no API key being
 * configured — instead of just continuing to spend.
 *
 * Built after a bug (three services independently re-querying the same Google
 * Places business on every enrichment run) burned ~$840 and ~123,500 Places calls
 * in a single day with nothing to stop it. Checked against completed jobs only,
 * not calls made so far by the currently-running job — worst case that undercounts
 * by one job's own in-flight spend (a few cents), which is fine for a breaker meant
 * to catch runaway spend across many jobs, not enforce exact-to-the-cent precision.
 */
class PlacesBudgetGuard
{
    /** Memoized per instance (one per queue job) — one DB query per job, not per call. */
    private ?bool $withinBudget = null;

    public function hasBudget(): bool
    {
        if ($this->withinBudget !== null) {
            return $this->withinBudget;
        }

        $budget = (float) config('enrichment.places_daily_budget_usd');

        if ($budget <= 0) {
            return $this->withinBudget = true; // 0/negative = no cap configured
        }

        $spentToday = (float) EnrichmentJob::where('created_at', '>=', now()->startOfDay())->sum('places_cost_estimate');

        $this->withinBudget = $spentToday < $budget;

        if (! $this->withinBudget) {
            Log::warning('PlacesBudgetGuard: daily Google Places budget exhausted, skipping further calls today', [
                'spent_today' => $spentToday,
                'budget' => $budget,
            ]);
        }

        return $this->withinBudget;
    }
}

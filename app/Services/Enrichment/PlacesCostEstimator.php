<?php

namespace App\Services\Enrichment;

/**
 * Shared Google Places call-count/cost bookkeeping, used wherever a run needs to
 * report what it spent on Places into enrichment_jobs.places_calls/places_cost_estimate
 * — EnrichmentPipeline (which combines WebsiteFinderService + GooglePlacesPhotoFinder
 * call counts) and FetchGooglePlacesPhotoJob (which only ever calls GooglePlacesPhotoFinder,
 * on its own 15-minute schedule outside the pipeline) both go through this so a Places
 * call costs the same on the dashboard no matter which job made it.
 */
class PlacesCostEstimator
{
    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    public static function mergeCallCounts(array $a, array $b = []): array
    {
        $merged = $a;

        foreach ($b as $key => $count) {
            $merged[$key] = ($merged[$key] ?? 0) + $count;
        }

        return array_filter($merged, fn (int $count) => $count > 0);
    }

    /** @param  array<string, int>  $calls */
    public static function estimateCost(array $calls): float
    {
        $pricing = config('enrichment.places_pricing', []);
        $cost = 0.0;

        foreach ($calls as $type => $count) {
            $cost += $count * ($pricing[$type] ?? 0.0);
        }

        return $cost;
    }
}

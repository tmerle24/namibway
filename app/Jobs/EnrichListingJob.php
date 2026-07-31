<?php

namespace App\Jobs;

use App\Models\EnrichmentJob;
use App\Models\Listing;
use App\Services\Enrichment\EnrichmentPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the full VisitNamibia enrichment pipeline for one listing and records
 * the run in enrichment_jobs, so the dashboard has an audit trail of every
 * enrichment attempt (source, success, tokens spent, cost estimate).
 *
 * Always go through the static queue() method rather than ::dispatch()
 * directly — it writes the enrichment_jobs row up front (started_at still
 * null) so "queued but no worker has picked it up yet" is visible on the
 * dashboard instead of indistinguishable from "nothing happened". Dispatched
 * by: NightlyEnrichListings (scheduled), the on-demand trigger in
 * ListingController@show, and the Filament dashboard's row/bulk actions
 * (which pass a $steps subset to run only part of the pipeline).
 */
class EnrichListingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    /** @param list<string>|null $steps */
    public function __construct(
        public readonly int $listingId,
        public readonly ?array $steps = null,
        public readonly ?int $enrichmentJobId = null,
    ) {}

    /** @param list<string>|null $steps */
    public static function queue(int $listingId, ?array $steps = null): EnrichmentJob
    {
        $run = EnrichmentJob::create([
            'listing_id' => $listingId,
            'source' => $steps ? implode(',', $steps) : 'full',
            'actor' => 'AI',
            'success' => false,
        ]);

        self::dispatch($listingId, $steps, $run->id);

        return $run;
    }

    public function uniqueId(): string
    {
        return $this->listingId.':'.($this->steps ? implode(',', $this->steps) : 'full');
    }

    public function handle(EnrichmentPipeline $pipeline): void
    {
        $listing = Listing::find($this->listingId);

        $run = ($this->enrichmentJobId ? EnrichmentJob::find($this->enrichmentJobId) : null)
            ?? EnrichmentJob::create([
                'listing_id' => $this->listingId,
                'source' => $this->steps ? implode(',', $this->steps) : 'full',
                'actor' => 'AI',
                'success' => false,
            ]);

        if (! $listing) {
            $run->update(['finished_at' => now(), 'success' => false, 'log' => 'Listing no longer exists.']);

            return;
        }

        $run->update(['started_at' => now()]);

        try {
            $result = $pipeline->run($listing, $this->steps);

            $run->update([
                'finished_at' => now(),
                'success' => true,
                'log' => implode("\n", $result['log']) ?: 'No changes — all tracked fields already had data.',
                'fields_changed' => $result['fields_updated'],
                'tokens_used' => $result['tokens_used'],
                'cost_estimate' => $result['cost_estimate'],
            ]);
        } catch (\Throwable $e) {
            Log::error("EnrichListingJob [{$this->listingId}] failed", ['error' => $e->getMessage()]);

            $run->update([
                'finished_at' => now(),
                'success' => false,
                'log' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

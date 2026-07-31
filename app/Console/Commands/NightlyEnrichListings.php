<?php

namespace App\Console\Commands;

use App\Jobs\EnrichListingJob;
use App\Models\Listing;
use Illuminate\Console\Command;

class NightlyEnrichListings extends Command
{
    protected $signature = 'listings:nightly-enrich
                            {--limit= : Max listings to queue (defaults to enrichment.nightly_batch_size)}
                            {--dry-run : List candidates without queuing anything}';

    protected $description = 'Queue the lowest-completion listings for the VisitNamibia data enrichment pipeline';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?: config('enrichment.nightly_batch_size'));

        $listings = Listing::query()
            ->orderByEnrichmentPriority()
            ->limit($limit)
            ->get(['id', 'slug', 'enrichment_score', 'last_enriched_at']);

        if ($listings->isEmpty()) {
            $this->info('No listings to enrich.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — nothing will be queued');
            $this->table(
                ['Listing', 'Score', 'Last enriched'],
                $listings->map(fn (Listing $l) => [$l->slug, $l->enrichment_score, $l->last_enriched_at?->toDateString() ?? 'never'])
            );

            return self::SUCCESS;
        }

        foreach ($listings as $listing) {
            EnrichListingJob::dispatch($listing->id);
        }

        $this->info("Queued {$listings->count()} listing(s) for enrichment.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\CrawlListingWebsiteJob;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeListingWebsites extends Command
{
    protected $signature = 'namibway:scrape-websites
                            {--limit=10         : Max listings to crawl in this run}
                            {--refresh-days=30  : Re-crawl listings whose last crawl is older than this many days}
                            {--dry-run          : List candidates without crawling}';

    protected $description = 'Crawl and import listing websites for content and photos (runs inline, no queue worker required)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $refreshDays = (int) $this->option('refresh-days');
        $dry = (bool) $this->option('dry-run');

        $listings = Listing::whereNotNull('website')
            ->where(function ($query) use ($refreshDays) {
                $query->whereNull('og_scraped_at')
                    ->orWhere('og_scraped_at', '<', now()->subDays($refreshDays));
            })
            ->orderByRaw('og_scraped_at ASC NULLS FIRST')
            ->limit($limit)
            ->get(['id', 'slug', 'website', 'og_scraped_at']);

        if ($listings->isEmpty()) {
            $this->info('No listings due for a website crawl.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->warn('DRY RUN — nothing will be crawled');
            $this->table(
                ['Listing', 'Website', 'Last crawled'],
                $listings->map(fn (Listing $l) => [$l->slug, $l->website, $l->og_scraped_at?->toDateString() ?? 'never'])
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($listings->count());
        $bar->start();

        foreach ($listings as $listing) {
            try {
                CrawlListingWebsiteJob::dispatchSync($listing->id);
            } catch (\Throwable $e) {
                // One bad listing (e.g. R2/network hiccup) shouldn't abort the whole batch —
                // it simply stays a candidate and gets retried on the next scheduled run.
                Log::warning('namibway:scrape-websites crawl failed', [
                    'listing_id' => $listing->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Crawled and imported {$listings->count()} listing(s).");

        return self::SUCCESS;
    }
}

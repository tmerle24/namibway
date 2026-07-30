<?php

namespace App\Console\Commands;

use App\Jobs\FetchGooglePlacesPhotoJob;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchGooglePlacesPhotos extends Command
{
    protected $signature = 'namibway:fetch-google-photos
                            {--limit=5          : Max listings to look up in this run}
                            {--refresh-days=90  : Re-check listings whose last lookup is older than this many days}
                            {--dry-run           : List candidates without calling the API}';

    protected $description = 'Look up real photos for imageless listings via the Google Places API (Find Place + Place Details + Photo)';

    public function handle(): int
    {
        if (! config('services.google_places.key')) {
            $this->warn('GOOGLE_PLACES_API_KEY is not set — skipping.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $refreshDays = (int) $this->option('refresh-days');
        $dry = (bool) $this->option('dry-run');

        $listings = Listing::whereNull('image')
            ->where(function ($query) use ($refreshDays) {
                $query->whereNull('google_photos_checked_at')
                    ->orWhere('google_photos_checked_at', '<', now()->subDays($refreshDays));
            })
            ->orderByRaw('google_photos_checked_at ASC NULLS FIRST')
            ->limit($limit)
            ->get(['id', 'slug', 'name', 'region', 'google_photos_checked_at']);

        if ($listings->isEmpty()) {
            $this->info('No listings due for a Google Places photo lookup.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->warn('DRY RUN — nothing will be looked up');
            $this->table(
                ['Listing', 'Region', 'Last checked'],
                $listings->map(fn (Listing $l) => [$l->name, $l->region, $l->google_photos_checked_at?->toDateString() ?? 'never'])
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($listings->count());
        $bar->start();

        foreach ($listings as $listing) {
            try {
                FetchGooglePlacesPhotoJob::dispatchSync($listing->id);
            } catch (\Throwable $e) {
                // One bad listing (rate limit, network hiccup) shouldn't abort the whole
                // batch — it simply stays a candidate and gets retried on the next run.
                Log::warning('namibway:fetch-google-photos lookup failed', [
                    'listing_id' => $listing->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Checked {$listings->count()} listing(s).");

        return self::SUCCESS;
    }
}

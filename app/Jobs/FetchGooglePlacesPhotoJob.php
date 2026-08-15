<?php

namespace App\Jobs;

use App\Models\EnrichmentJob;
use App\Models\Listing;
use App\Services\Enrichment\GooglePlacesLookupService;
use App\Services\Enrichment\GooglePlacesPhotoFinder;
use App\Services\Enrichment\PlacesCostEstimator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks up a listing on Google Places (by name + region) and pulls real
 * photos from its Place Details, for listings that have no photo of their
 * own and no website to crawl. Never overwrites an existing image/gallery.
 * Dispatched repeatedly over time by namibway:fetch-google-photos.
 *
 * Runs entirely outside EnrichmentPipeline/EnrichListingJob, but still writes
 * an enrichment_jobs row for every Places lookup it makes — otherwise this
 * job's spend would be invisible to the dashboard's cost tracking even though
 * it's the same billed API as everything else there.
 */
class FetchGooglePlacesPhotoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 45;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $listingId) {}

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(GooglePlacesPhotoFinder $photoFinder, GooglePlacesLookupService $placesLookup): void
    {
        if (blank(config('services.google_places.key'))) {
            return;
        }

        $listing = Listing::find($this->listingId);

        if (! $listing) {
            return;
        }

        // Always mark as checked, success or not, so a listing Google has no
        // match for doesn't get requeried on every run.
        $listing->update(['google_photos_checked_at' => now()]);

        if ($listing->image) {
            return;
        }

        $result = $photoFinder->findPhotoUrls($listing, $placesLookup);
        $urls = $result['urls'];

        $this->recordRun($listing, $photoFinder, $placesLookup, $urls);

        if (empty($urls)) {
            return;
        }

        $listing->update(array_filter([
            'image' => $urls[0],
            'gallery' => empty($listing->gallery) ? array_slice($urls, 1) : null,
            'photos_source' => 'google_places',
            'photos_attribution' => $result['attribution'],
        ], fn ($value) => $value !== null));
    }

    /** @param list<string> $urls */
    private function recordRun(Listing $listing, GooglePlacesPhotoFinder $photoFinder, GooglePlacesLookupService $placesLookup, array $urls): void
    {
        $calls = PlacesCostEstimator::mergeCallCounts($placesLookup->callCounts(), $photoFinder->callCounts());

        if ($calls === []) {
            return; // findPlaceId() never even ran — nothing was actually billed
        }

        EnrichmentJob::create([
            'listing_id' => $listing->id,
            'source' => 'google_photos',
            'actor' => 'Automated (no AI)',
            'success' => $urls !== [],
            'started_at' => now(),
            'finished_at' => now(),
            'log' => $urls !== [] ? count($urls).' photo(s) imported from Google Places.' : 'No Google Places photos found.',
            'fields_changed' => $urls !== [] ? ['image'] : [],
            'places_calls' => $calls,
            'places_cost_estimate' => round(PlacesCostEstimator::estimateCost($calls), 4),
        ]);
    }
}

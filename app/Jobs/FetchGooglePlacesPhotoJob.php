<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Services\Enrichment\GooglePlacesPhotoFinder;
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

    public function handle(GooglePlacesPhotoFinder $photoFinder): void
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

        $urls = $photoFinder->findPhotoUrls($listing);

        if (empty($urls)) {
            return;
        }

        $listing->update(array_filter([
            'image' => $urls[0],
            'gallery' => empty($listing->gallery) ? array_slice($urls, 1) : null,
        ], fn ($value) => $value !== null));
    }
}

<?php

namespace App\Jobs;

use App\Models\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /** Max photos (hero + gallery) to pull per listing. */
    private const MAX_IMAGES = 4;

    public function __construct(public readonly int $listingId) {}

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(): void
    {
        $apiKey = config('services.google_places.key');

        if (! $apiKey) {
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

        $placeId = $this->findPlaceId($apiKey, $listing);

        if (! $placeId) {
            return;
        }

        $photoReferences = $this->fetchPhotoReferences($apiKey, $placeId);

        if (empty($photoReferences)) {
            return;
        }

        $urls = [];

        foreach (array_slice($photoReferences, 0, self::MAX_IMAGES) as $reference) {
            $stored = $this->downloadPhoto($apiKey, $reference, $listing->slug);

            if ($stored) {
                $urls[] = $stored;
            }
        }

        if (empty($urls)) {
            return;
        }

        $listing->update(array_filter([
            'image' => $urls[0],
            'gallery' => empty($listing->gallery) ? array_slice($urls, 1) : null,
        ], fn ($value) => $value !== null));
    }

    private function findPlaceId(string $apiKey, Listing $listing): ?string
    {
        $query = trim($listing->name.' '.($listing->region ?: '').' Namibia');

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'input' => $query,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("FetchGooglePlacesPhotoJob [{$listing->id}]: findplacefromtext request failed", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning("FetchGooglePlacesPhotoJob [{$listing->id}]: findplacefromtext returned an error", ['status' => $response->status()]);

            return null;
        }

        return $response->json('candidates.0.place_id');
    }

    /** @return list<string> */
    private function fetchPhotoReferences(string $apiKey, string $placeId): array
    {
        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'photos',
                'key' => $apiKey,
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $photos = $response->json('result.photos');

        if (! is_array($photos)) {
            return [];
        }

        $refs = [];

        foreach ($photos as $photo) {
            if (is_array($photo) && isset($photo['photo_reference']) && is_string($photo['photo_reference'])) {
                $refs[] = $photo['photo_reference'];
            }
        }

        return $refs;
    }

    private function downloadPhoto(string $apiKey, string $photoReference, string $slug): ?string
    {
        try {
            $response = Http::timeout(20)->get('https://maps.googleapis.com/maps/api/place/photo', [
                'photoreference' => $photoReference,
                'maxwidth' => 1200,
                'key' => $apiKey,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');
            $ext = match (true) {
                str_contains((string) $contentType, 'png') => 'png',
                str_contains((string) $contentType, 'webp') => 'webp',
                default => 'jpg',
            };

            $filename = 'listings/google-places/'.$slug.'-'.Str::random(10).'.'.$ext;
            Storage::disk('r2')->put($filename, $response->body());

            return Storage::disk('r2')->url($filename);
        } catch (\Throwable) {
            return null;
        }
    }
}

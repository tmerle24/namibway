<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Looks a listing up on Google Places (by name + region) and downloads real
 * photos from its Place Details. Extracted from FetchGooglePlacesPhotoJob so
 * the enrichment pipeline can fall back to it when a listing has no website
 * to scrape photos from (or scraping its homepage found nothing) — the same
 * capability that job already provides on its own schedule.
 */
class GooglePlacesPhotoFinder
{
    private const MAX_IMAGES = 4;

    /** @var array<string, int> Google Places calls made by this instance — see callCounts(). */
    private array $callCounts = ['find_place' => 0, 'place_details' => 0, 'photo' => 0];

    /**
     * Google Places calls made since this instance was created, for cost-estimate
     * bookkeeping (EnrichmentPipeline sums this with WebsiteFinderService's). A fresh
     * instance is resolved per queue job, so this only ever reflects one enrichment run.
     *
     * @return array<string, int>
     */
    public function callCounts(): array
    {
        return $this->callCounts;
    }

    /** @return list<string> Public R2 URLs, hero image first. */
    public function findPhotoUrls(Listing $listing, int $max = self::MAX_IMAGES): array
    {
        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return [];
        }

        $placeId = $this->findPlaceId($apiKey, $listing);

        if (! $placeId) {
            return [];
        }

        $references = $this->fetchPhotoReferences($apiKey, $placeId, $listing->id);

        if (empty($references)) {
            return [];
        }

        $urls = [];

        foreach (array_slice($references, 0, $max) as $reference) {
            $stored = $this->downloadPhoto($apiKey, $reference, $listing->slug);

            if ($stored) {
                $urls[] = $stored;
            }
        }

        return $urls;
    }

    private function findPlaceId(string $apiKey, Listing $listing): ?string
    {
        $query = trim($listing->name.' '.($listing->region ?: '').' Namibia');

        $this->callCounts['find_place']++;

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'input' => $query,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("GooglePlacesPhotoFinder [{$listing->id}]: findplacefromtext request failed", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->json('candidates.0.place_id');
    }

    /** @return list<string> */
    private function fetchPhotoReferences(string $apiKey, string $placeId, int $listingId): array
    {
        $this->callCounts['place_details']++;

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'photos',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("GooglePlacesPhotoFinder [{$listingId}]: place details request failed", ['error' => $e->getMessage()]);

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
        $this->callCounts['photo']++;

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

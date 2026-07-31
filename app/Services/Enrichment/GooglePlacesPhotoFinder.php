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

    /**
     * @return array{urls: list<string>, attribution: string|null} Public R2 URLs (hero
     *                                                              image first) plus the
     *                                                              combined html_attributions
     *                                                              Google's Places API requires
     *                                                              we credit alongside them.
     */
    public function findPhotoUrls(Listing $listing, int $max = self::MAX_IMAGES): array
    {
        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return ['urls' => [], 'attribution' => null];
        }

        $placeId = $this->findPlaceId($apiKey, $listing);

        if (! $placeId) {
            return ['urls' => [], 'attribution' => null];
        }

        $photos = $this->fetchPhotoReferences($apiKey, $placeId, $listing->id);

        if (empty($photos)) {
            return ['urls' => [], 'attribution' => null];
        }

        $urls = [];
        $attributions = [];

        foreach (array_slice($photos, 0, $max) as $photo) {
            $stored = $this->downloadPhoto($apiKey, $photo['reference'], $listing->slug);

            if ($stored) {
                $urls[] = $stored;
                $attributions = [...$attributions, ...$photo['attributions']];
            }
        }

        return [
            'urls' => $urls,
            'attribution' => $attributions === [] ? null : implode(' · ', array_unique($attributions)),
        ];
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

        $status = $response->json('status');

        // OK / ZERO_RESULTS are the only "nothing went wrong" outcomes — anything else
        // (REQUEST_DENIED, OVER_QUERY_LIMIT, INVALID_REQUEST, ...) is a real config/quota
        // problem that would otherwise silently look identical to "no match found" for
        // every single listing, the way a referrer-restricted key did until this was added.
        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            Log::warning("GooglePlacesPhotoFinder [{$listing->id}]: findplacefromtext returned {$status}", [
                'error_message' => $response->json('error_message'),
            ]);

            return null;
        }

        return $response->json('candidates.0.place_id');
    }

    /** @return list<array{reference: string, attributions: list<string>}> */
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

        $status = $response->json('status');

        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            Log::warning("GooglePlacesPhotoFinder [{$listingId}]: place details returned {$status}", [
                'error_message' => $response->json('error_message'),
            ]);

            return [];
        }

        $photos = $response->json('result.photos');

        if (! is_array($photos)) {
            return [];
        }

        $refs = [];

        foreach ($photos as $photo) {
            if (is_array($photo) && isset($photo['photo_reference']) && is_string($photo['photo_reference'])) {
                $attributions = is_array($photo['html_attributions'] ?? null)
                    ? array_values(array_filter($photo['html_attributions'], 'is_string'))
                    : [];

                $refs[] = ['reference' => $photo['photo_reference'], 'attributions' => $attributions];
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

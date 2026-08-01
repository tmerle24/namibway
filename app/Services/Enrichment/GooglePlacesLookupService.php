<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single place every other Places-backed enrichment step goes through to look a
 * listing up on Google Places — one Find Place call, one Place Details call (requesting
 * every field any caller needs: website, phone, address, GPS, photos), memoized for the
 * lifetime of this instance.
 *
 * Before this existed, WebsiteFinderService::find(), WebsiteFinderService::
 * findLocationFacts(), and GooglePlacesPhotoFinder::findPhotoUrls() each independently
 * re-queried Google Places for the *same* business — up to ~14 billed calls per listing.
 * A single EnrichmentPipeline run now shares one GooglePlacesLookupService instance
 * across all three, cutting that to at most 2 (or 4, if the by-region query variant
 * comes up empty and the by-type variant is tried).
 */
class GooglePlacesLookupService
{
    /** @var array<string, int> Google Places calls made by this instance — see callCounts(). */
    private array $callCounts = ['find_place' => 0, 'place_details' => 0];

    /** @var array<int, array{place_id: string, website: string|null, formatted_phone_number: string|null, formatted_address: string|null, lat: float|null, lng: float|null, photos: list<array{photo_reference: string, html_attributions: list<string>}>}|null> Keyed by listing ID. */
    private array $cache = [];

    /**
     * Google Places calls made since this instance was created, for cost-estimate
     * bookkeeping. A fresh instance is resolved per queue job, so this only ever
     * reflects one enrichment run.
     *
     * @return array<string, int>
     */
    public function callCounts(): array
    {
        return $this->callCounts;
    }

    /**
     * @return array{place_id: string, website: string|null, formatted_phone_number: string|null, formatted_address: string|null, lat: float|null, lng: float|null, photos: list<array{photo_reference: string, html_attributions: list<string>}>}|null
     */
    public function lookup(Listing $listing): ?array
    {
        if (array_key_exists($listing->id, $this->cache)) {
            return $this->cache[$listing->id];
        }

        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return $this->cache[$listing->id] = null;
        }

        $placeId = $this->findPlaceId($apiKey, $listing);

        return $this->cache[$listing->id] = $placeId ? $this->fetchPlaceDetails($apiKey, $placeId, $listing->id) : null;
    }

    private function findPlaceId(string $apiKey, Listing $listing): ?string
    {
        // Region first (more specific, usually correct); type + "Namibia" as a fallback
        // for listings with no region on file yet.
        foreach ([true, false] as $byRegion) {
            $query = $byRegion
                ? trim($listing->name.' '.($listing->region ?: '').' Namibia')
                : trim($listing->name.' '.$listing->type->value.' Namibia');

            $this->callCounts['find_place']++;

            try {
                $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                    'input' => $query,
                    'inputtype' => 'textquery',
                    'fields' => 'place_id',
                    'key' => $apiKey,
                ]);
            } catch (\Throwable $e) {
                Log::warning("GooglePlacesLookupService [{$listing->id}]: findplacefromtext request failed", ['error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $status = $response->json('status');

            if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                Log::warning("GooglePlacesLookupService [{$listing->id}]: findplacefromtext returned {$status}", [
                    'error_message' => $response->json('error_message'),
                ]);

                continue;
            }

            $placeId = $response->json('candidates.0.place_id');

            if ($placeId) {
                return $placeId;
            }
        }

        return null;
    }

    /**
     * @return array{place_id: string, website: string|null, formatted_phone_number: string|null, formatted_address: string|null, lat: float|null, lng: float|null, photos: list<array{photo_reference: string, html_attributions: list<string>}>}|null
     */
    private function fetchPlaceDetails(string $apiKey, string $placeId, int $listingId): ?array
    {
        $this->callCounts['place_details']++;

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'website,formatted_phone_number,formatted_address,geometry,photos',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("GooglePlacesLookupService [{$listingId}]: place details request failed", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = $response->json('status');

        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            Log::warning("GooglePlacesLookupService [{$listingId}]: place details returned {$status}", [
                'error_message' => $response->json('error_message'),
            ]);

            return null;
        }

        $result = $response->json('result') ?? [];

        $photos = [];

        foreach ($result['photos'] ?? [] as $photo) {
            if (is_array($photo) && isset($photo['photo_reference']) && is_string($photo['photo_reference'])) {
                $photos[] = [
                    'photo_reference' => $photo['photo_reference'],
                    'html_attributions' => is_array($photo['html_attributions'] ?? null)
                        ? array_values(array_filter($photo['html_attributions'], 'is_string'))
                        : [],
                ];
            }
        }

        return [
            'place_id' => $placeId,
            'website' => $result['website'] ?? null,
            'formatted_phone_number' => $result['formatted_phone_number'] ?? null,
            'formatted_address' => $result['formatted_address'] ?? null,
            'lat' => $result['geometry']['location']['lat'] ?? null,
            'lng' => $result['geometry']['location']['lng'] ?? null,
            'photos' => $photos,
        ];
    }
}

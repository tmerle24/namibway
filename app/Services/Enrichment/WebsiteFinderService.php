<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Step 2 of the enrichment pipeline: find a listing's website, following the
 * priority chain from the spec:
 *
 *   A) Listing already has a contact email -> extract its domain and probe
 *      https(s)://(www.)domain directly. Confidence 100.
 *   B) No email -> look the listing up on Google Places by name + region;
 *      Place Details also hands back phone/address/GPS for free. Confidence 95.
 *   C) Still nothing -> broaden the Google Places query to name + category +
 *      "Namibia" (dropping region, which may be missing/wrong). Confidence 85.
 *
 * There is no general web-search API configured for this project (no SerpAPI/
 * Bing key) — Google Places doubles as the "search by name" step in both B
 * and C, since NTB listings are almost always real, mappable businesses.
 */
class WebsiteFinderService
{
    /** @var array<string, int> Google Places calls made by this instance — see callCounts(). */
    private array $callCounts = ['find_place' => 0, 'place_details' => 0];

    /**
     * Google Places calls made since this instance was created, for cost-estimate
     * bookkeeping (EnrichmentPipeline sums this with GooglePlacesPhotoFinder's). A fresh
     * instance is resolved per queue job, so this only ever reflects one enrichment run.
     *
     * @return array<string, int>
     */
    public function callCounts(): array
    {
        return $this->callCounts;
    }

    /**
     * @return array{website: string, confidence: int, source: string, phone?: string, address?: string, latitude?: float, longitude?: float}|null
     */
    public function find(Listing $listing, bool $useGooglePlaces = true): ?array
    {
        $found = $this->fromEmailDomain($listing);

        if ($found !== null || ! $useGooglePlaces) {
            return $found;
        }

        return $this->fromGooglePlaces($listing, byRegion: true, confidence: 95)
            ?? $this->fromGooglePlaces($listing, byRegion: false, confidence: 85);
    }

    /** @return array{website: string, confidence: int, source: string}|null */
    private function fromEmailDomain(Listing $listing): ?array
    {
        if (blank($listing->contact_email) || ! str_contains($listing->contact_email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(explode('@', $listing->contact_email)[1] ?? ''));

        if ($domain === '' || $this->isFreemailDomain($domain)) {
            return null;
        }

        foreach (["https://{$domain}", "https://www.{$domain}"] as $candidate) {
            if ($this->respondsOk($candidate)) {
                return ['website' => $candidate, 'confidence' => 100, 'source' => 'email_domain'];
            }
        }

        return null;
    }

    /**
     * Independent of find()/fromGooglePlaces(): those require a website in the Place
     * Details response to return anything at all, discarding a perfectly good geocode
     * whenever a business simply doesn't have a website on file with Google. This is
     * for the pipeline's GPS/address/phone fill-in step, which has no such requirement.
     *
     * @return array{phone?: string, address?: string, latitude?: float, longitude?: float}
     */
    public function findLocationFacts(Listing $listing): array
    {
        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return [];
        }

        foreach ([true, false] as $byRegion) {
            $query = $byRegion
                ? trim($listing->name.' '.($listing->region ?: '').' Namibia')
                : trim($listing->name.' '.$listing->type->value.' Namibia');

            $placeId = $this->findPlaceId($apiKey, $query, $listing->id);

            if (! $placeId) {
                continue;
            }

            $details = $this->fetchPlaceDetails($apiKey, $placeId, $listing->id);
            $facts = [];

            if (filled($details['formatted_phone_number'] ?? null)) {
                $facts['phone'] = $details['formatted_phone_number'];
            }

            if (filled($details['formatted_address'] ?? null)) {
                $facts['address'] = $details['formatted_address'];
            }

            if (($details['lat'] ?? null) !== null && ($details['lng'] ?? null) !== null) {
                $facts['latitude'] = (float) $details['lat'];
                $facts['longitude'] = (float) $details['lng'];
            }

            if ($facts !== []) {
                return $facts;
            }
        }

        return [];
    }

    /** @return array{website: string, confidence: int, source: string, phone?: string, address?: string, latitude?: float, longitude?: float}|null */
    private function fromGooglePlaces(Listing $listing, bool $byRegion, int $confidence): ?array
    {
        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return null;
        }

        $query = $byRegion
            ? trim($listing->name.' '.($listing->region ?: '').' Namibia')
            : trim($listing->name.' '.$listing->type->value.' Namibia');

        $placeId = $this->findPlaceId($apiKey, $query, $listing->id);

        if (! $placeId) {
            return null;
        }

        $details = $this->fetchPlaceDetails($apiKey, $placeId, $listing->id);

        if (blank($details['website'] ?? null)) {
            return null;
        }

        $found = [
            'website' => $details['website'],
            'confidence' => $confidence,
            'source' => 'google_places',
        ];

        if (filled($details['formatted_phone_number'] ?? null)) {
            $found['phone'] = $details['formatted_phone_number'];
        }

        if (filled($details['formatted_address'] ?? null)) {
            $found['address'] = $details['formatted_address'];
        }

        if (($details['lat'] ?? null) !== null && ($details['lng'] ?? null) !== null) {
            $found['latitude'] = (float) $details['lat'];
            $found['longitude'] = (float) $details['lng'];
        }

        return $found;
    }

    private function findPlaceId(string $apiKey, string $query, int $listingId): ?string
    {
        $this->callCounts['find_place']++;

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'input' => $query,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("WebsiteFinderService [{$listingId}]: findplacefromtext request failed", ['error' => $e->getMessage()]);

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
            Log::warning("WebsiteFinderService [{$listingId}]: findplacefromtext returned {$status}", [
                'error_message' => $response->json('error_message'),
            ]);

            return null;
        }

        return $response->json('candidates.0.place_id');
    }

    /** @return array<string, mixed> */
    private function fetchPlaceDetails(string $apiKey, string $placeId, int $listingId): array
    {
        $this->callCounts['place_details']++;

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'website,formatted_phone_number,formatted_address,geometry',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning("WebsiteFinderService [{$listingId}]: place details request failed", ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $status = $response->json('status');

        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            Log::warning("WebsiteFinderService [{$listingId}]: place details returned {$status}", [
                'error_message' => $response->json('error_message'),
            ]);

            return [];
        }

        $result = $response->json('result') ?? [];

        return [
            'website' => $result['website'] ?? null,
            'formatted_phone_number' => $result['formatted_phone_number'] ?? null,
            'formatted_address' => $result['formatted_address'] ?? null,
            'lat' => $result['geometry']['location']['lat'] ?? null,
            'lng' => $result['geometry']['location']['lng'] ?? null,
        ];
    }

    private function respondsOk(string $url): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NamibWay/1.0; +https://namibway.com)'])
                ->get($url);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful();
    }

    private function isFreemailDomain(string $domain): bool
    {
        return in_array($domain, ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'live.com', 'gmx.net', 'gmx.de', 'web.de'], true);
    }
}

<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free first-line source for GPS + address: OpenStreetMap's Nominatim search API.
 * No API key, no per-call cost — EnrichmentPipeline::fillLocationFacts() tries this
 * before falling back to the paid Google Places lookup, so most listings never need
 * to hit Places at all. Coverage is patchy for small lodges/operators that aren't
 * mapped on OSM, which is exactly what the Places fallback is still there for.
 *
 * Usage policy (https://operations.osmfoundation.org/policies/nominatim/) caps the
 * public endpoint at 1 request/second and requires a real, identifying User-Agent —
 * both enforced below.
 */
class OsmLocationFinder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const USER_AGENT = 'NamibWay/1.0 (+https://namibway.com; enrichment@namibway.com)';

    /** @return array{phone?: string, address?: string, latitude?: float, longitude?: float} */
    public function findLocationFacts(Listing $listing): array
    {
        foreach ([true, false] as $byRegion) {
            $query = $byRegion
                ? trim($listing->name.' '.($listing->region ?: '').' Namibia')
                : trim($listing->name.' Namibia');

            $facts = $this->search($query, $listing->id);

            if ($facts !== null) {
                return $facts;
            }
        }

        return [];
    }

    /** @return array{phone?: string, address?: string, latitude?: float, longitude?: float}|null */
    private function search(string $query, int $listingId): ?array
    {
        $this->throttle();

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get(self::ENDPOINT, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'extratags' => 1,
                    'countrycodes' => 'na',
                    'limit' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning("OsmLocationFinder [{$listingId}]: search request failed", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $result = $response->json('0');

        if (! is_array($result) || ! isset($result['lat'], $result['lon'])) {
            return null;
        }

        $facts = [
            'latitude' => (float) $result['lat'],
            'longitude' => (float) $result['lon'],
        ];

        if (! empty($result['display_name'])) {
            $facts['address'] = $result['display_name'];
        }

        $phone = $result['extratags']['phone'] ?? $result['extratags']['contact:phone'] ?? null;

        if (! empty($phone)) {
            $facts['phone'] = $phone;
        }

        return $facts;
    }

    /** Nominatim's usage policy caps the public endpoint at 1 request/second, enforced globally across workers. */
    private function throttle(): void
    {
        $attempts = 0;

        while (! Cache::add('nominatim-rate-limit', true, 1) && $attempts < 10) {
            usleep(150_000);
            $attempts++;
        }
    }
}

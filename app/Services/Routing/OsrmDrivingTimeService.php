<?php

namespace App\Services\Routing;

use App\Models\City;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real city-to-city driving durations via OSRM's Table API — the city-level
 * counterpart to ItineraryService::DRIVING_HOURS (which is region-level,
 * hand-verified once and kept as a compact hardcoded table since it's only
 * 91 pairs; a city-level equivalent would be hundreds of pairs, too many to
 * hand-verify or hardcode, so this fetches and caches real values instead —
 * see namibway:backfill-city-driving-hours, the only caller).
 *
 * The Table API computes a whole pairwise duration matrix in one HTTP call,
 * unlike the single-route API which would need one call per pair. The public
 * demo server (default base URL) caps how many coordinates one request can
 * carry, so durationMatrix() chunks the input and issues one call per
 * chunk-pair rather than a single call for everything.
 *
 * Never called during a live Kaia request — this only powers the one-time/
 * periodic backfill command, so its latency has no bearing on Kaia's <10s
 * budget.
 */
class OsrmDrivingTimeService
{
    private const USER_AGENT = 'NamibWay/1.0 (+https://namibway.com; enrichment@namibway.com)';

    /** Keeps each Table API request's coordinate count comfortably under the public demo server's size cap. */
    private const CHUNK_SIZE = 25;

    /**
     * Computes real one-way driving hours between every unordered pair of the
     * given cities (each must already have lat/lng — callers are expected to
     * have filtered for that). A pair OSRM couldn't route, or that a failed
     * request left uncomputed, is simply absent from the result — never a
     * fabricated/guessed value.
     *
     * @param  Collection<int, City>  $cities
     * @return array<int, array{city_a_id: int, city_b_id: int, hours: float}>
     */
    public function durationMatrix(Collection $cities): array
    {
        $groups = $cities->values()->chunk(self::CHUNK_SIZE)->values();
        $results = [];

        for ($i = 0; $i < $groups->count(); $i++) {
            for ($j = $i; $j < $groups->count(); $j++) {
                array_push($results, ...$this->chunkPair($groups[$i], $groups[$j], sameGroup: $i === $j));
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, City>  $sources
     * @param  Collection<int, City>  $destinations
     * @return array<int, array{city_a_id: int, city_b_id: int, hours: float}>
     */
    private function chunkPair(Collection $sources, Collection $destinations, bool $sameGroup): array
    {
        $sources = $sources->values();
        $coordinates = $sameGroup ? $sources : $sources->concat($destinations->values());

        $sourceIdx = range(0, $sources->count() - 1);
        $destIdx = $sameGroup ? $sourceIdx : range($sources->count(), $coordinates->count() - 1);

        $durations = $this->table($coordinates, $sourceIdx, $destIdx);

        if ($durations === null) {
            return [];
        }

        $pairs = [];
        $seen = [];

        foreach ($sourceIdx as $si => $srcCoordIdx) {
            $srcCity = $coordinates[$srcCoordIdx];

            foreach ($destIdx as $di => $dstCoordIdx) {
                $dstCity = $coordinates[$dstCoordIdx];

                if ($srcCity->id === $dstCity->id) {
                    continue;
                }

                $seconds = $durations[$si][$di] ?? null;

                if ($seconds === null) {
                    continue;
                }

                $a = min($srcCity->id, $dstCity->id);
                $b = max($srcCity->id, $dstCity->id);
                $key = "{$a}|{$b}";

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $pairs[] = ['city_a_id' => $a, 'city_b_id' => $b, 'hours' => round($seconds / 3600, 2)];
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, City>  $coordinates
     * @param  array<int, int>  $sourceIdx
     * @param  array<int, int>  $destIdx
     * @return array<int, array<int, float|null>>|null  seconds, [source][destination]
     */
    private function table(Collection $coordinates, array $sourceIdx, array $destIdx): ?array
    {
        $this->throttle();

        // OSRM coordinates are lon,lat — the opposite of City's lat/lng column order.
        $coordString = $coordinates->map(fn (City $city) => "{$city->lng},{$city->lat}")->implode(';');
        $baseUrl = rtrim((string) config('services.osrm.base_url'), '/');

        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get("{$baseUrl}/table/v1/driving/{$coordString}", [
                    'sources' => implode(';', $sourceIdx),
                    'destinations' => implode(';', $destIdx),
                    'annotations' => 'duration',
                ]);
        } catch (Throwable $e) {
            Log::warning('OsrmDrivingTimeService: table request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OsrmDrivingTimeService: table request returned an error', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $durations = $response->json('durations');

        return is_array($durations) ? $durations : null;
    }

    /** Not as strictly documented as Nominatim's, but a light lock keeps this a good citizen of the shared public demo server. */
    private function throttle(): void
    {
        $attempts = 0;

        while (! Cache::add('osrm-rate-limit', true, 1) && $attempts < 10) {
            usleep(150_000);
            $attempts++;
        }
    }
}

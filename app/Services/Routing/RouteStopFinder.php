<?php

namespace App\Services\Routing;

use App\Models\Attraction;
use App\Models\City;
use App\Models\Place;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * What is worth stopping for between two stages of a trip.
 *
 * Namibia's legs are long — Windhoek to Etosha is most of a day — and the
 * things beside that road are exactly what a traveller regrets driving past.
 * They are already in `attractions`; what was missing is the question "which
 * of them are on *this* road", which is what this answers, for the drive-time
 * box in the trip plan.
 *
 * ## Why detour rather than distance from a line
 *
 * The obvious test is the perpendicular distance from the straight line
 * between the two stages. It is the wrong one: it says a site 20 km to the
 * side is equally worth stopping for whether it sits at the midpoint or 5 km
 * before the destination, and it has no way to express "this is on the way"
 * as a traveller means it. What a traveller means is *what it costs me to go
 * there*, so that is what is computed:
 *
 *     detour = distance(from → attraction) + distance(attraction → to)
 *              - distance(from → to)
 *
 * Zero for something on the line, and it grows the further off the road it
 * gets — the same number the traveller would work out themselves.
 *
 * Detour alone is not quite enough, though, and the arithmetic says why: a
 * point at perpendicular distance p from the middle of a leg of length L costs
 * roughly 2p²/L in detour, so on a 500 km leg a 40 km detour reaches 100 km to
 * the side. Forty extra kilometres is forty extra kilometres — but at 100 km
 * off the road the straight line stops being an approximation of anything, and
 * whatever is out there is reached by a different road, not by a stop on this
 * one. So a corridor caps how far to the side "beside this road" can mean, and
 * it binds only on the long legs, which is exactly where the straight-line
 * detour stops being believable.
 *
 * These are straight-line kilometres, not road kilometres. That is deliberate
 * and is the reason the thresholds are generous: this runs on a live plan
 * render, and paying for a routing call per candidate to sharpen a filter
 * whose output is five names in a chip row would be absurd. The number is
 * never shown as a driving distance; it only decides what is offered, and it
 * is stated as an approximation where it surfaces at all.
 *
 * ## What is deliberately excluded
 *
 * - **Anything at either end.** Heroes' Acre is a Windhoek sight, not a stop
 *   on the road out of Windhoek. Two tests, because coordinates alone are not
 *   enough: within NEAR_ENDPOINT_KM of either stage, *or* filed under a place
 *   that is a stage anywhere on this route. The second is what keeps Okaukuejo
 *   Waterhole — 60 km inside a park whose centroid is somewhere else entirely
 *   — out of the drive that arrives at Etosha.
 * - **A leg too short to break.** Under MIN_LEG_KM nobody is looking for
 *   somewhere to stretch their legs, and a stop on a 40-minute drive reads as
 *   clutter rather than as a find.
 * - **The same site twice.** On a round trip the outbound and return legs run
 *   the same road; the stop is offered on the way out, where a traveller with
 *   a whole day ahead of them can still take it.
 */
class RouteStopFinder
{
    /** The most extra driving a short stop may cost, whatever the leg's length. */
    private const MAX_DETOUR_KM = 40.0;

    /** …and never more than this share of the leg itself, so a short drive gets a short leash. */
    private const MAX_DETOUR_SHARE = 0.25;

    /**
     * How far to the side of the leg "beside this road" can mean. Also the
     * bounding box's padding, and therefore the reason the box is exact rather
     * than a guess — see the class docblock.
     */
    private const MAX_CORRIDOR_KM = 60.0;

    /** Closer than this to either stage and it belongs to that stage, not to the drive. */
    private const NEAR_ENDPOINT_KM = 25.0;

    /** Below this, a leg is a transfer rather than a drive, and has no room for a stop. */
    private const MIN_LEG_KM = 50.0;

    /**
     * Enough to be worth a look, few enough to stay a discreet line under a
     * driving time. The plan is not a directory.
     */
    private const MAX_PER_LEG = 5;

    /** @var array<string, RoutePoint|null> */
    private array $resolved = [];

    /**
     * @param  array<int, array{from: string, to: string}>  $legs  in travel order
     * @return array<int, array{from: string, to: string, stops: Collection<int, Attraction>}>
     */
    public function forLegs(array $legs): array
    {
        $points = [];

        foreach ($legs as $leg) {
            $points[] = $this->resolve($leg['from']);
            $points[] = $this->resolve($leg['to']);
        }

        $points = array_values(array_filter($points));

        if ($points === []) {
            return array_map(
                fn (array $leg): array => $leg + ['stops' => collect()],
                $legs,
            );
        }

        $stagePlaceIds = array_values(array_unique(array_filter(
            array_map(fn (RoutePoint $p): ?int => $p->placeId, $points),
        )));

        $candidates = $this->candidates($points, $stagePlaceIds);

        // First leg wins, so a round trip offers each stop on the way out.
        $taken = [];
        $result = [];

        foreach ($legs as $leg) {
            $stops = $this->forLeg($leg['from'], $leg['to'], $candidates)
                ->reject(fn (Attraction $a): bool => isset($taken[$a->id]));

            foreach ($stops as $stop) {
                $taken[$stop->id] = true;
            }

            $result[] = $leg + ['stops' => $stops->values()];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Attraction>  $candidates
     * @return Collection<int, Attraction>
     */
    private function forLeg(string $from, string $to, Collection $candidates): Collection
    {
        $origin = $this->resolve($from);
        $destination = $this->resolve($to);

        if ($origin === null || $destination === null) {
            return collect();
        }

        $direct = Geo::distanceKm($origin->lat, $origin->lng, $destination->lat, $destination->lng);

        if ($direct < self::MIN_LEG_KM) {
            return collect();
        }

        $maxDetour = min(self::MAX_DETOUR_KM, $direct * self::MAX_DETOUR_SHARE);

        return $candidates
            ->map(function (Attraction $attraction) use ($origin, $destination, $direct): Attraction {
                $fromKm = Geo::distanceKm($origin->lat, $origin->lng, (float) $attraction->lat, (float) $attraction->lng);
                $toKm = Geo::distanceKm($destination->lat, $destination->lng, (float) $attraction->lat, (float) $attraction->lng);

                // A fresh model per leg: the same attraction can be a candidate
                // on two legs with two different detours, and one shared
                // instance would carry whichever was computed last.
                $stop = clone $attraction;
                $stop->setAttribute('detour_km', max(0.0, $fromKm + $toKm - $direct));
                $stop->setAttribute('from_origin_km', $fromKm);
                $stop->setAttribute('from_destination_km', $toKm);
                $stop->setAttribute('corridor_km', self::corridorKm($fromKm, $toKm, $direct));

                return $stop;
            })
            ->filter(fn (Attraction $a): bool => (float) $a->getAttribute('detour_km') <= $maxDetour
                && (float) $a->getAttribute('corridor_km') <= self::MAX_CORRIDOR_KM
                && (float) $a->getAttribute('from_origin_km') >= self::NEAR_ENDPOINT_KM
                && (float) $a->getAttribute('from_destination_km') >= self::NEAR_ENDPOINT_KM)
            // In the order they come up through the windscreen, which is the
            // only order a list of roadside stops can sensibly be read in.
            ->sortBy(fn (Attraction $a): float => (float) $a->getAttribute('from_origin_km'))
            ->take(self::MAX_PER_LEG)
            ->values();
    }

    /**
     * How far to the side of the leg a point sits, from the three distances
     * already computed: the triangle's height on the leg as its base.
     *
     * A plane triangle over great-circle sides, which at these distances is
     * off by less than the coordinates themselves are. Zero-length base cannot
     * happen — a leg that short is refused before this is reached — but is
     * guarded anyway rather than dividing by it.
     */
    private static function corridorKm(float $fromKm, float $toKm, float $direct): float
    {
        if ($direct <= 0.0) {
            return 0.0;
        }

        $s = ($fromKm + $toKm + $direct) / 2;
        $area = sqrt(max(0.0, $s * ($s - $fromKm) * ($s - $toKm) * ($s - $direct)));

        return 2 * $area / $direct;
    }

    /**
     * Everything that could be on any of these legs, in one query.
     *
     * Narrowed by a bounding box around the whole route rather than loaded
     * whole: the table is small today and will not stay that way, and the
     * index over (lat, lng) exists for exactly this. The padding is the
     * corridor width, so nothing that would survive the filters can fall
     * outside the box — a box padded by the detour allowance instead would
     * silently drop candidates on the long legs.
     *
     * @param  array<int, RoutePoint>  $points
     * @param  array<int, int>  $stagePlaceIds
     * @return Collection<int, Attraction>
     */
    private function candidates(array $points, array $stagePlaceIds): Collection
    {
        $lats = array_map(fn (RoutePoint $p): float => $p->lat, $points);
        $lngs = array_map(fn (RoutePoint $p): float => $p->lng, $points);

        if ($lats === [] || $lngs === []) {
            return collect();
        }

        $padLat = Geo::latDegreesForKm(self::MAX_CORRIDOR_KM);
        $padLng = Geo::lngDegreesForKm(self::MAX_CORRIDOR_KM, max(abs(min($lats)), abs(max($lats))));

        $query = Attraction::query()
            ->where('is_published', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [min($lats) - $padLat, max($lats) + $padLat])
            ->whereBetween('lng', [min($lngs) - $padLng, max($lngs) + $padLng])
            ->with(['place.region', 'place.destination', 'city.region', 'city.destination']);

        if ($stagePlaceIds !== []) {
            $query->where(fn ($q) => $q->whereNull('place_id')->orWhereNotIn('place_id', $stagePlaceIds));
        }

        return $query->get();
    }

    /**
     * A stage name to a point on the map.
     *
     * A day's location is a place; a plan saved before places were split out
     * of cities carries a city name, and those have to keep working, so the
     * city is a fallback rather than an error. Memoised because a route names
     * every stage twice — once as an arrival, once as a departure.
     */
    public function resolve(string $name): ?RoutePoint
    {
        $key = mb_strtolower(trim($name));

        if ($key === '') {
            return null;
        }

        return $this->resolved[$key] ??= $this->lookUp($key);
    }

    private function lookUp(string $key): ?RoutePoint
    {
        $place = Place::query()
            ->whereRaw('lower(cast(name as text)) like ?', ['%"'.$key.'"%'])
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first();

        if ($place !== null) {
            return new RoutePoint((float) $place->lat, (float) $place->lng, $place->id);
        }

        $city = City::query()
            ->where('name', 'ilike', $key)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first();

        return $city === null
            ? null
            : new RoutePoint(
                (float) $city->lat,
                (float) $city->lng,
                $city->place_id === null ? null : (int) $city->place_id,
            );
    }
}

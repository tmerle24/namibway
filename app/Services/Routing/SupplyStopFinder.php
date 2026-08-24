<?php

namespace App\Services\Routing;

use App\Enums\SupplyService;
use App\Models\Listing;
use App\Models\SupplyPoint;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Where to fill up, and where to buy food, before the road stops offering it.
 *
 * The sibling of RouteStopFinder and deliberately not the same rule. That one
 * asks "what is beside this road?" — a question about one leg, answered by a
 * position on it. This one asks "will I regret not stopping?", which no single
 * leg can answer: whether the pumps at Kamanjab matter depends entirely on
 * what comes after Kamanjab. So a supply point is named for its **relation to
 * the road ahead**, and the whole route is needed to work it out.
 *
 * ## The rule
 *
 * Every supply point near the route gets a position along it — kilometres from
 * the start, by projection onto the leg it sits beside. Per service, those
 * positions form a sequence, and the gap after each one is the distance to the
 * next place with that service (or to the end of the route). A stop is named
 * when the gap after it is at least SupplyService::gapKm() — 200 km for fuel,
 * 250 km for groceries.
 *
 * That is what makes it self-limiting, and it is why the interesting question
 * was never "where are the filling stations". Windhoek has thirty; twenty-nine
 * of them have another one a kilometre later, so the gap after them is a
 * kilometre and not one is named. What gets named is the last one before the
 * empty stretch, which is the only one anybody needed to be told about.
 *
 * Groceries carry a second trigger, because distance is not what makes a
 * supermarket matter: a stage where the traveller cooks for themselves and
 * where nothing is sold. If a self-catering stay lies between one grocery stop
 * and the next, that stop is named however short the gap — this is "the last
 * supermarket before a self-catering camp", and it is why the endpoint accepts
 * the stay each leg arrives at.
 *
 * ## Where it deliberately differs from RouteStopFinder
 *
 * - **Nothing is excluded for being at a stage.** The pumps in the town you
 *   are sleeping in are not "already part of that stage" — they are the reason
 *   the gap there is zero, and dropping them would invent a gap that does not
 *   exist. A supply point at a shared stage matches both the leg arriving and
 *   the leg leaving, which is load-bearing rather than a duplicate: the arrival
 *   entry closes the previous gap, and the departure entry is the one named
 *   when a long empty leg follows. So "fill up in Otjiwarongo" appears above
 *   the drive that needs it, not above the drive that ended there.
 * - **No minimum leg length.** A stop worth naming is worth naming on a 30 km
 *   transfer: the road after it does not care how short this leg was.
 * - **A stop may be named twice on a round trip.** You need fuel in both
 *   directions. Attractions are offered once because seeing something twice is
 *   pointless; filling up twice is the entire idea.
 * - **The same corridor as the attraction finder, not a tighter one.** The
 *   first instinct was to narrow it — nobody detours 40 km for diesel. That is
 *   the wrong way round: a stop is only ever named when it is the last chance,
 *   and 40 km is exactly what somebody would drive for the last chance. The
 *   corridor is not a measure of willingness, it is the distance beyond which
 *   the straight line stops approximating any road, and that distance does not
 *   change with what is being bought.
 *
 * Straight-line kilometres throughout, for the same reason as the attraction
 * finder: this runs on a live plan render and a routing call per candidate to
 * sharpen a filter whose output is one chip would be absurd. The gap is shown
 * as an approximation and never as a road distance — and it is a *lower*
 * bound, since roads are longer than lines, which is the safe direction for a
 * number a traveller plans fuel around.
 *
 * What this cannot know: whether the pump has fuel in it today. That is what
 * SupplyPoint::$verified_at and the note are for, and why the copy says "last
 * fuel for ≈240 km" rather than promising anything about what is in the tank.
 */
class SupplyStopFinder
{
    /** The most extra driving a supply stop may cost before it is on a different road. */
    private const MAX_DETOUR_KM = 40.0;

    /** …and never more than this share of the leg. */
    private const MAX_DETOUR_SHARE = 0.25;

    /**
     * …but never less than this, so a short transfer can still reach the town
     * beside it. Without the floor a 20 km leg would accept a 5 km detour and
     * miss the only supermarket for a hundred kilometres — the share exists to
     * keep a short drive from reaching across the country, not to make the
     * shortest drives blind.
     */
    private const MIN_DETOUR_KM = 10.0;

    /** How far to the side of the leg "on this road" can mean. */
    private const MAX_CORRIDOR_KM = 60.0;

    /**
     * How close to a stage a shop has to be to count as *at* that stage —
     * which is what stops "the last supermarket before a self-catering camp"
     * firing for a camp with a supermarket across the road.
     */
    private const NEAR_STAGE_KM = 10.0;

    /**
     * Two is the honest maximum (fuel and food); the third is headroom for a
     * leg where one place covers one of them and another covers the other.
     * More than that under a driving time is a directory, not a hint.
     */
    private const MAX_PER_LEG = 3;

    public function __construct(private readonly RoutePointResolver $points) {}

    /**
     * @param  array<int, array{from: string, to: string, stay_slug?: string|null}>  $legs  in travel order
     * @return array<int, array{from: string, to: string, stops: array<int, SupplyStop>}>
     */
    public function forLegs(array $legs): array
    {
        $geometry = $this->geometry($legs);
        $resolved = array_filter($geometry);

        if ($resolved === []) {
            return array_map(fn (array $leg): array => [
                'from' => $leg['from'],
                'to' => $leg['to'],
                'stops' => [],
            ], $legs);
        }

        $stages = $this->stages($legs, $geometry);
        $entries = $this->entries($geometry, $this->candidates($resolved));

        /** @var array<int, array<int, array{point: SupplyPoint, position: float, detour: float, reasons: array<int, SupplyReason>}>> $named */
        $named = [];

        foreach ([SupplyService::Fuel, SupplyService::Groceries] as $service) {
            foreach ($this->reasons($service, $entries, $stages, $geometry) as [$entry, $reason]) {
                $leg = $entry['leg'];
                $id = $entry['point']->id;

                $named[$leg][$id] ??= [
                    'point' => $entry['point'],
                    'position' => $entry['position'],
                    'detour' => $entry['detour'],
                    'reasons' => [],
                ];
                $named[$leg][$id]['reasons'][] = $reason;
            }
        }

        $result = [];

        foreach ($legs as $index => $leg) {
            $stops = array_values($named[$index] ?? []);

            // In the order they come up through the windscreen.
            usort($stops, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            $result[] = [
                'from' => $leg['from'],
                'to' => $leg['to'],
                'stops' => array_map(
                    fn (array $stop): SupplyStop => new SupplyStop(
                        $stop['point'],
                        $stop['reasons'],
                        $stop['position'],
                        $stop['detour'],
                    ),
                    array_slice($stops, 0, self::MAX_PER_LEG),
                ),
            ];
        }

        return $result;
    }

    /**
     * Each leg as a piece of measurable road: where it starts along the route,
     * how long it is, and which segment it belongs to.
     *
     * A leg whose ends cannot be resolved is a hole, and nothing can be
     * measured across a hole — a gap that spans one would be a claim about a
     * stretch of road we know nothing about. So a hole starts a new segment,
     * and gaps are only ever computed inside one.
     *
     * @param  array<int, array{from: string, to: string, stay_slug?: string|null}>  $legs
     * @return array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}|null>
     */
    private function geometry(array $legs): array
    {
        $geometry = [];
        $offset = 0.0;
        $segment = 0;
        $lastIndex = array_key_last($legs);

        foreach ($legs as $index => $leg) {
            $origin = $this->points->resolve($leg['from']);
            $destination = $this->points->resolve($leg['to']);

            if ($origin === null || $destination === null) {
                $geometry[$index] = null;
                $segment++;

                continue;
            }

            $direct = Geo::distanceKm($origin->lat, $origin->lng, $destination->lat, $destination->lng);

            $geometry[$index] = [
                'origin' => $origin,
                'destination' => $destination,
                'direct' => $direct,
                'offset' => $offset,
                'segment' => $segment,
                // Only the segment that runs to the last leg of the plan may
                // measure a gap against "the rest of the route" — anywhere
                // else, what comes after the segment is simply unknown.
                'ends_route' => $index === $lastIndex,
            ];

            $offset += $direct;
        }

        return $geometry;
    }

    /**
     * The stages the traveller arrives at, as positions along the route, with
     * whether they cook for themselves there.
     *
     * @param  array<int, array{from: string, to: string, stay_slug?: string|null}>  $legs
     * @param  array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}|null>  $geometry
     * @return array<int, array{position: float, segment: int, self_catering: bool}>
     */
    private function stages(array $legs, array $geometry): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(
            fn (array $leg): ?string => $leg['stay_slug'] ?? null,
            $legs,
        ))));

        // Which of the named stays the traveller cooks at, as a set of slugs.
        // The browser sends slugs and nothing else; whether a stay is
        // self-catering is Listing's answer to give, not the form's.
        $selfCatering = $slugs === []
            ? []
            : Listing::query()
                ->whereIn('slug', $slugs)
                ->get()
                ->filter(fn (Listing $listing): bool => $listing->isSelfCatering())
                ->pluck('slug')
                ->flip()
                ->all();

        $stages = [];

        foreach ($legs as $index => $leg) {
            $geo = $geometry[$index] ?? null;

            if ($geo === null) {
                continue;
            }

            $slug = $leg['stay_slug'] ?? null;

            $stages[] = [
                'position' => $geo['offset'] + $geo['direct'],
                'segment' => $geo['segment'],
                'self_catering' => $slug !== null && isset($selfCatering[$slug]),
            ];
        }

        return $stages;
    }

    /**
     * Everything published and locatable inside a box around the whole route.
     *
     * Padded by the corridor width, so nothing that would survive the filters
     * can fall outside the box.
     *
     * @param  array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}>  $legs
     * @return Collection<int, SupplyPoint>
     */
    private function candidates(array $legs): Collection
    {
        $lats = [];
        $lngs = [];

        foreach ($legs as $leg) {
            foreach ([$leg['origin'], $leg['destination']] as $point) {
                $lats[] = $point->lat;
                $lngs[] = $point->lng;
            }
        }

        $padLat = Geo::latDegreesForKm(self::MAX_CORRIDOR_KM);
        $padLng = Geo::lngDegreesForKm(self::MAX_CORRIDOR_KM, max(abs(min($lats)), abs(max($lats))));

        return SupplyPoint::query()
            ->where('is_published', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [min($lats) - $padLat, max($lats) + $padLat])
            ->whereBetween('lng', [min($lngs) - $padLng, max($lngs) + $padLng])
            ->with(['place.region', 'city.region'])
            ->get();
    }

    /**
     * Every time the traveller passes a supply point, as a position along the
     * route.
     *
     * One entry per (leg, supply point): a point beside a shared stage is
     * passed on both the leg that arrives and the leg that leaves, and both of
     * those are real chances to fill up. See the class docblock for why that
     * matters rather than being a duplicate to squash.
     *
     * @param  array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}|null>  $geometry
     * @param  Collection<int, SupplyPoint>  $candidates
     * @return array<int, array{leg: int, segment: int, position: float, detour: float, point: SupplyPoint, ends_route: bool}>
     */
    private function entries(array $geometry, Collection $candidates): array
    {
        $entries = [];

        foreach ($geometry as $index => $leg) {
            if ($leg === null) {
                continue;
            }

            $direct = $leg['direct'];
            $maxDetour = max(self::MIN_DETOUR_KM, min(self::MAX_DETOUR_KM, $direct * self::MAX_DETOUR_SHARE));

            foreach ($candidates as $point) {
                $fromKm = Geo::distanceKm($leg['origin']->lat, $leg['origin']->lng, (float) $point->lat, (float) $point->lng);
                $toKm = Geo::distanceKm($leg['destination']->lat, $leg['destination']->lng, (float) $point->lat, (float) $point->lng);
                $detour = max(0.0, $fromKm + $toKm - $direct);

                if ($detour > $maxDetour || self::corridorKm($fromKm, $toKm, $direct) > self::MAX_CORRIDOR_KM) {
                    continue;
                }

                $entries[] = [
                    'leg' => $index,
                    'segment' => $leg['segment'],
                    'position' => $leg['offset'] + self::projectionKm($fromKm, $toKm, $direct),
                    'detour' => $detour,
                    'point' => $point,
                    'ends_route' => $leg['ends_route'],
                ];
            }
        }

        return $entries;
    }

    /**
     * Which of those passings are worth telling the traveller about, for one
     * service.
     *
     * @param  array<int, array{leg: int, segment: int, position: float, detour: float, point: SupplyPoint, ends_route: bool}>  $entries
     * @param  array<int, array{position: float, segment: int, self_catering: bool}>  $stages
     * @param  array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}|null>  $geometry
     * @return array<int, array{0: array{leg: int, segment: int, position: float, detour: float, point: SupplyPoint, ends_route: bool}, 1: SupplyReason}>
     */
    private function reasons(SupplyService $service, array $entries, array $stages, array $geometry): array
    {
        $providers = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['point']->provides($service),
        ));

        usort($providers, fn (array $a, array $b): int => [$a['segment'], $a['position']] <=> [$b['segment'], $b['position']]);

        $ends = $this->segmentEnds($geometry);
        $named = [];

        foreach ($providers as $i => $entry) {
            $next = $providers[$i + 1] ?? null;

            if ($next !== null && $next['segment'] !== $entry['segment']) {
                $next = null;
            }

            $segmentEnd = $ends[$entry['segment']]['position'];
            $reachesRouteEnd = $ends[$entry['segment']]['ends_route'];

            $upTo = $next === null ? $segmentEnd : $next['position'];
            $gap = $upTo - $entry['position'];

            // Without a next provider inside this segment, only a segment that
            // runs to the end of the plan knows how far the empty stretch
            // really goes. Everywhere else the road continues past what we can
            // measure, and a number would be a guess.
            $gapIsKnown = $next !== null || $reachesRouteEnd;

            $beforeSelfCatering = $service === SupplyService::Groceries
                && $this->selfCateringAhead($entry, $upTo, $stages, $providers);

            if (! $beforeSelfCatering && ! ($gapIsKnown && $gap >= $service->gapKm())) {
                continue;
            }

            $named[] = [$entry, new SupplyReason($service, max(0.0, $gap), $beforeSelfCatering)];
        }

        return $named;
    }

    /**
     * Whether the traveller cooks for themselves somewhere between this stop
     * and the next place that sells food.
     *
     * A stage with a shop of its own does not count, however self-catering the
     * stay is — the camp store across the road is not a reason to load the car
     * two hundred kilometres earlier.
     *
     * @param  array{leg: int, segment: int, position: float, detour: float, point: SupplyPoint, ends_route: bool}  $entry
     * @param  array<int, array{position: float, segment: int, self_catering: bool}>  $stages
     * @param  array<int, array{leg: int, segment: int, position: float, detour: float, point: SupplyPoint, ends_route: bool}>  $providers
     */
    private function selfCateringAhead(array $entry, float $upTo, array $stages, array $providers): bool
    {
        foreach ($stages as $stage) {
            if ($stage['segment'] !== $entry['segment'] || ! $stage['self_catering']) {
                continue;
            }

            if ($stage['position'] <= $entry['position'] || $stage['position'] > $upTo) {
                continue;
            }

            $supplied = false;

            foreach ($providers as $provider) {
                if ($provider['segment'] === $stage['segment']
                    && abs($provider['position'] - $stage['position']) <= self::NEAR_STAGE_KM) {
                    $supplied = true;

                    break;
                }
            }

            if (! $supplied) {
                return true;
            }
        }

        return false;
    }

    /**
     * How far each segment of measurable road runs, and whether it is the one
     * the plan ends on.
     *
     * @param  array<int, array{origin: RoutePoint, destination: RoutePoint, direct: float, offset: float, segment: int, ends_route: bool}|null>  $geometry
     * @return array<int, array{position: float, ends_route: bool}>
     */
    private function segmentEnds(array $geometry): array
    {
        $ends = [];

        foreach ($geometry as $leg) {
            if ($leg === null) {
                continue;
            }

            $segment = $leg['segment'];
            $end = $leg['offset'] + $leg['direct'];

            $ends[$segment] = [
                'position' => max($end, $ends[$segment]['position'] ?? 0.0),
                'ends_route' => $leg['ends_route'] || ($ends[$segment]['ends_route'] ?? false),
            ];
        }

        return $ends;
    }

    /**
     * How far along the leg a point sits — the foot of its perpendicular,
     * from the two distances already computed. Clamped to the leg, so a point
     * beside one of the ends cannot land outside it.
     */
    private static function projectionKm(float $fromKm, float $toKm, float $direct): float
    {
        if ($direct <= 0.0) {
            return 0.0;
        }

        return max(0.0, min($direct, ($fromKm ** 2 - $toKm ** 2 + $direct ** 2) / (2 * $direct)));
    }

    /**
     * How far to the side of the leg a point sits: the triangle's height on
     * the leg as its base. Same arithmetic as RouteStopFinder's, and the same
     * plane-triangle approximation over great-circle sides.
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
}

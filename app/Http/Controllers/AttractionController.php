<?php

namespace App\Http\Controllers;

use App\Models\Attraction;
use App\Services\Routing\RoutePointResolver;
use App\Services\Routing\RouteStopFinder;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three things the trip plan needs from an attraction: a way to find one
 * near where the traveller is, a way to find one *between* where they are and
 * where they are going, and a way to look at one.
 *
 * Kept apart from ListingController rather than folded into its search,
 * because the two answer different questions. A listing search filters on
 * price, rating and vehicle class; none of those exist here, and bolting an
 * attraction onto a paginated listing query would mean pretending they do. The
 * response shape *is* deliberately the same, so the swap modal can show both
 * lists side by side without a second card design.
 */
class AttractionController extends Controller
{
    /** How far from the day's location an attraction may be and still be offered. */
    private const DEFAULT_RADIUS_KM = 150.0;

    public function search(Request $request, RoutePointResolver $points): JsonResponse
    {
        $query = Attraction::query()
            ->where('is_published', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->with(['place.region', 'place.destination', 'city.region', 'city.destination']);

        $keyword = $request->query('keyword');

        if (is_string($keyword) && $keyword !== '') {
            $like = '%'.mb_strtolower($keyword).'%';

            $query->where(fn ($q) => $q
                ->whereRaw('lower(cast(name as text)) like ?', [$like])
                ->orWhereRaw('lower(cast(coalesce(summary, \'{}\') as text)) like ?', [$like]));
        }

        // "Near" is resolved from a place name — the itinerary day's own
        // location — so the browser never handles raw coordinates, same
        // contract as ListingController::search.
        $reference = $this->referencePoint($request, $points);
        $results = $query->get();

        if ($reference !== null) {
            [$refLat, $refLng] = $reference;

            $radius = is_numeric($request->query('max_distance_km'))
                ? (float) $request->query('max_distance_km')
                : self::DEFAULT_RADIUS_KM;

            $results = $results
                ->map(function (Attraction $attraction) use ($refLat, $refLng): Attraction {
                    $attraction->setAttribute('distance_km', Geo::distanceKm(
                        $refLat, $refLng, (float) $attraction->lat, (float) $attraction->lng,
                    ));

                    return $attraction;
                })
                ->filter(fn (Attraction $a): bool => (float) $a->getAttribute('distance_km') <= $radius)
                ->sortBy(fn (Attraction $a): float => (float) $a->getAttribute('distance_km'))
                ->values();
        }

        return response()->json([
            'data' => $results->take(12)->map(fn (Attraction $a) => $this->present($a))->values(),
        ]);
    }

    /**
     * What is worth stopping for on each leg of a route.
     *
     * One request for the whole plan rather than one per leg: a trip has a
     * dozen legs, they change together whenever a day is dragged or a stage
     * added, and twelve round trips to say "nothing here" four times over is
     * not a thing to do on a page render.
     *
     * The geometry lives in RouteStopFinder; this only decides what a browser
     * is allowed to ask for and what comes back.
     */
    public function alongRoute(Request $request, RouteStopFinder $finder): JsonResponse
    {
        $validated = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:30'],
            'legs.*.from' => ['required', 'string', 'max:120'],
            'legs.*.to' => ['required', 'string', 'max:120'],
        ]);

        /** @var array<int, array{from: string, to: string}> $legs */
        $legs = array_map(
            fn (array $leg): array => ['from' => $leg['from'], 'to' => $leg['to']],
            array_values($validated['legs']),
        );

        return response()->json([
            'legs' => array_map(fn (array $leg): array => [
                'from' => $leg['from'],
                'to' => $leg['to'],
                'stops' => $leg['stops']
                    ->map(fn (Attraction $a): array => $this->present($a) + [
                        // Straight-line and stated as approximate wherever it
                        // is shown — see RouteStopFinder for why it is not a
                        // road distance.
                        'detour_km' => round((float) $a->getAttribute('detour_km')),
                    ])
                    ->values(),
            ], $finder->forLegs($legs)),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $attraction = Attraction::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with(['place.region', 'place.destination', 'city.region', 'city.destination'])
            ->firstOrFail();

        return response()->json($this->present($attraction, full: true));
    }

    /**
     * The reference point for "near here", from a place name and then a city
     * name — a day's location is a place, but a plan saved before the split
     * carries a city name and must keep working.
     *
     * Shared with the route-stop search, which resolves the ends of a leg
     * through the same RoutePointResolver: two answers to "where is this
     * stage?" would eventually differ, and a plan whose two attraction lists
     * disagree about where Etosha is has no way to explain itself.
     *
     * @return array{0: float, 1: float}|null
     */
    private function referencePoint(Request $request, RoutePointResolver $points): ?array
    {
        $name = $request->query('reference_city');

        if (! is_string($name) || $name === '') {
            return null;
        }

        $point = $points->resolve($name);

        return $point === null ? null : [$point->lat, $point->lng];
    }

    /**
     * Deliberately the same keys a listing search result carries, with the
     * ones that cannot apply left null rather than faked: an attraction has no
     * rating, no vehicle class and usually no price.
     *
     * @return array<string, mixed>
     */
    private function present(Attraction $attraction, bool $full = false): array
    {
        $anchor = $attraction->place ?? $attraction->city;

        $data = [
            'id' => $attraction->id,
            'type' => 'attraction',
            'attraction_type' => $attraction->type->value,
            'attraction_type_label' => $attraction->type->getLabel(),
            'vehicle_category' => null,
            'vehicle_class' => null,
            'name' => $attraction->getTranslation('name', 'en', useFallbackLocale: true),
            'slug' => $attraction->slug,
            'short_description' => $attraction->getTranslation('summary', 'en', useFallbackLocale: true) ?: null,
            'image' => $attraction->image ? self::resolveMediaUrl($attraction->image) : null,
            'gallery' => collect($attraction->gallery ?? [])
                ->map(fn (string $path) => self::resolveMediaUrl($path))
                ->values(),
            'highlights' => [],
            'region' => $anchor?->region?->name,
            'area' => $anchor?->destination?->name,
            'city' => $anchor?->name,
            'latitude' => $attraction->lat,
            'longitude' => $attraction->lng,
            // Usually null, and null means "nobody has checked", not "free" —
            // see the seed migration.
            'price_from' => $attraction->entry_fee,
            'price_currency' => $attraction->entry_fee_currency,
            'price_unit' => null,
            'duration_minutes' => $attraction->visit_minutes,
            'rating' => null,
            'rating_count' => null,
            'is_featured' => false,
        ];

        if ($attraction->getAttribute('distance_km') !== null) {
            $data['distance_km'] = round((float) $attraction->getAttribute('distance_km'), 1);
        }

        if (! $full) {
            return $data;
        }

        // The long text and the operational facts, for the detail modal only —
        // they must never reach the itinerary prompt.
        return $data + [
            'description' => $attraction->getTranslation('description', 'en', useFallbackLocale: true) ?: null,
            'access_note' => $attraction->getTranslation('access_note', 'en', useFallbackLocale: true) ?: null,
            'best_time_note' => $attraction->getTranslation('best_time_note', 'en', useFallbackLocale: true) ?: null,
            'requires_4x4' => $attraction->requires_4x4,
            'requires_permit' => $attraction->requires_permit,
            'photos_attribution' => $attraction->photos_attribution,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Routing\SupplyReason;
use App\Services\Routing\SupplyStop;
use App\Services\Routing\SupplyStopFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where to fill up and where to buy food, for the drive-time box in the trip
 * plan.
 *
 * A sibling of AttractionController::alongRoute and deliberately its own
 * endpoint rather than another key in that response. Two reasons: the two
 * questions fail independently — an empty attraction catalogue must not take
 * the fuel line down with it, and vice versa — and this one needs something
 * the other has no use for, the stay each leg arrives at, which is what
 * answers "is anybody cooking their own dinner tonight?".
 *
 * One request covers the whole route, because the rule is about the road ahead
 * and cannot be answered a leg at a time. The rule itself lives in
 * SupplyStopFinder; this only decides what a browser may ask for and what
 * comes back.
 */
class SupplyStopController extends Controller
{
    public function alongRoute(Request $request, SupplyStopFinder $finder): JsonResponse
    {
        $validated = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:30'],
            'legs.*.from' => ['required', 'string', 'max:120'],
            'legs.*.to' => ['required', 'string', 'max:120'],
            // The accommodation the leg arrives at, where the plan has one.
            // A slug, never a claim: whether that stay is self-catering is
            // read off the listing here, not asserted by the browser.
            'legs.*.stay_slug' => ['nullable', 'string', 'max:190'],
        ]);

        /** @var array<int, array{from: string, to: string, stay_slug: string|null}> $legs */
        $legs = array_map(fn (array $leg): array => [
            'from' => $leg['from'],
            'to' => $leg['to'],
            'stay_slug' => $leg['stay_slug'] ?? null,
        ], array_values($validated['legs']));

        return response()->json([
            'legs' => array_map(fn (array $leg): array => [
                'from' => $leg['from'],
                'to' => $leg['to'],
                'stops' => array_map($this->present(...), $leg['stops']),
            ], $finder->forLegs($legs)),
        ]);
    }

    /**
     * Nothing here is a card: a supply stop is a name, a reason and the two
     * facts that decide whether it is any use — when it is open and which pump
     * it has. Enum values travel as their stored strings and are named by the
     * browser's own translations, so a German traveller is not shown "Fuel"
     * because an English-speaking content manager typed the row.
     *
     * @return array<string, mixed>
     */
    private function present(SupplyStop $stop): array
    {
        $point = $stop->point;
        $anchor = $point->place ?? $point->city;

        return [
            'id' => $point->id,
            'slug' => $point->slug,
            'name' => $point->name,
            'services' => $point->serviceList()->map(fn ($service): string => $service->value)->values(),
            'fuel_types' => $point->fuelTypeList()->map(fn ($fuel): string => $fuel->value)->values(),
            'opening_hours' => $point->openingHours()?->toArray(),
            // OpenStreetMap data is ODbL: where an imported time is shown, the
            // credit goes with it. Null for hours somebody typed.
            'opening_hours_from_osm' => str_starts_with((string) $point->opening_hours_source, 'osm:'),
            'note' => $point->getTranslation('note', app()->getLocale(), useFallbackLocale: true) ?: null,
            'city' => $anchor?->name,
            'region' => $anchor?->region?->name,
            // Straight-line, so a lower bound on what the road does — see
            // SupplyStopFinder. Shown with a "≈" wherever it appears.
            'detour_km' => (int) round($stop->detourKm),
            'verified' => $point->verified_at !== null,
            'reasons' => array_map(fn (SupplyReason $reason): array => [
                'service' => $reason->service->value,
                'gap_km' => (int) round($reason->gapKm),
                'before_self_catering' => $reason->beforeSelfCatering,
            ], $stop->reasons),
        ];
    }
}

<?php

namespace App\Services\Trip;

use App\Models\City;
use App\Models\Destination;
use App\Models\Place;

/**
 * Where a saved plan's day locations are on the map.
 *
 * A day's "location" is a free string written by the model and resolved
 * against whatever the catalog knew at the time, so this index has to answer
 * for plans of every vintage — a place name (what a location has been since
 * 2026-08-23), a city name (before that), or a political region (before
 * that again). Precedence is the same as KaiaController::regionCoords(),
 * which serves the interactive map: destination, then political region, then
 * place, then city, never overwriting an earlier answer.
 *
 * Deliberately narrower than that endpoint — coordinates only, no photo, area
 * or region — because the two renderers that use it (the PDF route sketch and
 * the share card) draw dots, not cards. And deliberately not restricted to
 * cities with a published listing: a plan may be older than a listing's
 * unpublishing, and a drawn map should still place the stop it was saved with.
 */
class PlanWaypoints
{
    /**
     * Keyed by the lowercased location name, which is what a day carries.
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    public function coordinates(): array
    {
        $coords = [];

        $destinations = Destination::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('sort_order')
            ->with('region:id,name')
            ->get(['id', 'name', 'lat', 'lng', 'region_id']);

        foreach ($destinations as $destination) {
            $coords[mb_strtolower($destination->getTranslation('name', 'en'))] = [
                'lat' => (float) $destination->lat,
                'lng' => (float) $destination->lng,
            ];
        }

        foreach ($destinations as $destination) {
            $key = mb_strtolower($destination->region->name);

            $coords[$key] ??= ['lat' => (float) $destination->lat, 'lng' => (float) $destination->lng];
        }

        Place::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng'])
            ->each(function (Place $place) use (&$coords) {
                $coords[mb_strtolower($place->name)] ??= [
                    'lat' => (float) $place->lat,
                    'lng' => (float) $place->lng,
                ];
            });

        City::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng'])
            ->each(function (City $city) use (&$coords) {
                $coords[mb_strtolower($city->name)] ??= [
                    'lat' => (float) $city->lat,
                    'lng' => (float) $city->lng,
                ];
            });

        return $coords;
    }

    /**
     * The plan's stops in order, with consecutive days at one place collapsed
     * into a single waypoint and any location the index can't place dropped.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    public function forDays(array $days, array $coords): array
    {
        $waypoints = [];
        $previous = '';

        foreach ($days as $day) {
            $label = trim((string) ($day['location'] ?? ''));
            $key = mb_strtolower($label);

            if ($key === '' || $key === $previous || ! isset($coords[$key])) {
                continue;
            }

            $waypoints[] = ['label' => $label, ...$coords[$key]];
            $previous = $key;
        }

        return $waypoints;
    }
}

<?php

namespace App\Services\Routing;

use App\Models\City;
use App\Models\Place;

/**
 * The one answer to "where is this stage?".
 *
 * A trip plan carries its stages as names — "Etosha", "Swakopmund" — and
 * three different things now need them as coordinates: what is worth seeing
 * between two of them (RouteStopFinder), where you can fill up before the next
 * one (SupplyStopFinder), and what is near the one you are standing in
 * (AttractionController::search). They resolve here rather than each having a
 * lookup of its own, because a plan whose two lists disagree about where
 * Etosha is has no way to explain itself — which is the reason this was pulled
 * out of RouteStopFinder the moment a second caller appeared.
 *
 * Memoised per instance: a route names every stage twice, once as an arrival
 * and once as the next leg's departure, and an instance lives for one request.
 */
class RoutePointResolver
{
    /** @var array<string, RoutePoint|null> */
    private array $resolved = [];

    public function resolve(string $name): ?RoutePoint
    {
        $key = mb_strtolower(trim($name));

        if ($key === '') {
            return null;
        }

        return $this->resolved[$key] ??= $this->lookUp($key);
    }

    /**
     * A day's location is a place; a plan saved before places were split out
     * of cities carries a city name, and those have to keep working, so the
     * city is a fallback rather than an error.
     */
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

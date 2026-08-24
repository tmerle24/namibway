<?php

namespace App\Support;

/**
 * Great-circle distance, in one place.
 *
 * Four copies of the same haversine had grown across the codebase before this
 * existed. It stays a plain static helper rather than a service because it
 * takes no dependencies and answers the same question everywhere: two points
 * on a sphere, kilometres between them.
 *
 * Straight-line, not road distance — every caller here uses it as a cheap
 * filter before something more expensive (a route lookup, a model call), never
 * as a driving distance quoted to a traveller.
 */
final class Geo
{
    /** Mean Earth radius, kilometres. */
    public const EARTH_RADIUS_KM = 6371.0;

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * How many degrees of longitude a given distance spans at this latitude.
     *
     * Needed because a bounding box in degrees is not square: a degree of
     * longitude is 111 km at the equator and 103 km at Windhoek's latitude,
     * and a box built as if it were square is wrong in the direction that
     * silently drops rows.
     */
    public static function lngDegreesForKm(float $km, float $atLatitude): float
    {
        $kmPerDegree = 111.32 * cos(deg2rad($atLatitude));

        return $kmPerDegree < 1.0 ? 180.0 : $km / $kmPerDegree;
    }

    /** How many degrees of latitude a given distance spans. Constant, unlike longitude. */
    public static function latDegreesForKm(float $km): float
    {
        return $km / 110.57;
    }
}

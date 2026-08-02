<?php

namespace App\Services\Enrichment;

/**
 * Some partner websites publish only GPS coordinates, not a street address — the AI
 * extractor (see AIListingExtractorService) then faithfully saves that coordinate text
 * into `address`. Detect that case so callers can convert it into real lat/lng instead
 * of showing a raw "S20°47.482 E016°42.704" string as if it were a street address.
 */
class CoordinateTextParser
{
    /** @return array{0: float, 1: float}|null [latitude, longitude] */
    public static function parse(string $address): ?array
    {
        $pattern = '/^([NS])\s*(\d{1,2})°\s*(\d{1,2}(?:\.\d+)?)[\'′]?\s*([EW])\s*(\d{1,3})°\s*(\d{1,2}(?:\.\d+)?)[\'′]?/i';

        if (! preg_match($pattern, trim($address), $m)) {
            return null;
        }

        $latitude = ((float) $m[2]) + ((float) $m[3]) / 60;
        $longitude = ((float) $m[5]) + ((float) $m[6]) / 60;

        return [
            strtoupper($m[1]) === 'S' ? -$latitude : $latitude,
            strtoupper($m[4]) === 'W' ? -$longitude : $longitude,
        ];
    }
}

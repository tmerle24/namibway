<?php

namespace App\Sites\Rendering;

/**
 * The drawn expedition map: a country outline and a route across it, projected
 * into SVG coordinates once on the server, so the page ships a few paths and
 * no map library.
 *
 * Plate carree with the longitude squeezed by the cosine of the middle
 * latitude - at this scale nobody can tell it from a real projection, and it
 * keeps the arithmetic one line.
 */
final class RouteMap
{
    public const WIDTH = 600;

    /**
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return array{width: int, height: int, outline: string, route: string, points: list<array{x: float, y: float}>}|null
     */
    public static function for(string $country, array $stops): ?array
    {
        /** @var list<array{0: float, 1: float}> $outline */
        $outline = config('map_outlines.'.strtoupper($country), []);

        if ($outline === []) {
            return null;
        }

        $lngs = array_column($outline, 0);
        $lats = array_column($outline, 1);
        $minLng = min($lngs);
        $maxLat = max($lats);
        $squeeze = cos(deg2rad((max($lats) + min($lats)) / 2));
        $pad = 24;
        $scale = (self::WIDTH - 2 * $pad) / ((max($lngs) - $minLng) * $squeeze);
        $height = (int) ceil((max($lats) - min($lats)) * $scale + 2 * $pad);

        $project = fn (float $lng, float $lat): array => [
            'x' => round($pad + ($lng - $minLng) * $squeeze * $scale, 1),
            'y' => round($pad + ($maxLat - $lat) * $scale, 1),
        ];

        $border = array_map(fn (array $p): array => $project((float) $p[0], (float) $p[1]), $outline);
        $points = array_map(fn (array $s): array => $project((float) $s['lng'], (float) $s['lat']), $stops);

        return [
            'width' => self::WIDTH,
            'height' => $height,
            'outline' => self::path($border, true),
            'route' => self::curve($points),
            'points' => $points,
        ];
    }

    /**
     * @param  list<array{x: float, y: float}>  $points
     */
    private static function path(array $points, bool $closed): string
    {
        $d = '';

        foreach ($points as $i => $p) {
            $d .= ($i === 0 ? 'M' : 'L').$p['x'].' '.$p['y'];
        }

        return $closed ? $d.'Z' : $d;
    }

    /**
     * The road between the stops, bowed a little on every leg so it reads as a
     * journey drawn by hand rather than a set of straight lines.
     *
     * @param  list<array{x: float, y: float}>  $points
     */
    private static function curve(array $points): string
    {
        if (count($points) < 2) {
            return '';
        }

        $d = 'M'.$points[0]['x'].' '.$points[0]['y'];

        for ($i = 1; $i < count($points); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            // Control point off the middle of the leg, alternating sides.
            $bow = ($i % 2 === 0 ? 1 : -1) * 0.18;
            $cx = round(($a['x'] + $b['x']) / 2 - ($b['y'] - $a['y']) * $bow, 1);
            $cy = round(($a['y'] + $b['y']) / 2 + ($b['x'] - $a['x']) * $bow, 1);
            $d .= 'Q'.$cx.' '.$cy.' '.$b['x'].' '.$b['y'];
        }

        return $d;
    }
}

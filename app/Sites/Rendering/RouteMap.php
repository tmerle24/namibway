<?php

namespace App\Sites\Rendering;

/**
 * The drawn expedition map: a country outline, the landmarks an old chart
 * carries, and a route across it - projected into SVG coordinates once on the
 * server, so the page ships a few paths and no map library.
 *
 * Plate carree with the longitude squeezed by the cosine of the middle
 * latitude - at this scale nobody can tell it from a real projection, and it
 * keeps the arithmetic one line. Country data: config/map_outlines.php.
 */
final class RouteMap
{
    public const WIDTH = 600;

    /**
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return array<string, mixed>|null
     */
    public static function for(string $country, array $stops): ?array
    {
        /** @var array<string, mixed> $data */
        $data = config('map_outlines.'.strtoupper($country), []);
        /** @var list<array{0: float, 1: float}> $outline */
        $outline = $data['outline'] ?? [];

        if ($outline === []) {
            return null;
        }

        $lngs = array_column($outline, 0);
        $lats = array_column($outline, 1);
        $minLng = min($lngs);
        $maxLat = max($lats);
        $squeeze = cos(deg2rad((max($lats) + min($lats)) / 2));
        $pad = 30;
        $scale = (self::WIDTH - 2 * $pad) / ((max($lngs) - $minLng) * $squeeze);
        $height = (int) ceil((max($lats) - min($lats)) * $scale + 2 * $pad);

        $project = fn (float $lng, float $lat): array => [
            'x' => round($pad + ($lng - $minLng) * $squeeze * $scale, 1),
            'y' => round($pad + ($maxLat - $lat) * $scale, 1),
        ];
        $line = fn (array $pairs): array => array_values(array_map(fn (array $p): array => $project((float) $p[0], (float) $p[1]), $pairs));

        $points = array_map(fn (array $s): array => $project((float) $s['lng'], (float) $s['lat']), $stops);

        return [
            'width' => self::WIDTH,
            'height' => $height,
            'outline' => self::path($line($outline), true),
            'route' => self::curve($points),
            'points' => $points,
            'labels' => array_map(fn (array $l): array => ['name' => (string) $l[0]] + $project((float) $l[1], (float) $l[2]), $data['labels'] ?? []),
            'rivers' => array_map(fn (array $r): string => self::path($line($r), false), $data['rivers'] ?? []),
            'pans' => array_map(fn (array $p): array => [
                'name' => (string) $p[0],
                'rx' => round((float) $p[3] * $squeeze * $scale, 1),
                'ry' => round((float) $p[4] * $scale, 1),
            ] + $project((float) $p[1], (float) $p[2]), $data['pans'] ?? []),
            'sand' => isset($data['sand']) ? self::path($line($data['sand']), true) : null,
            'mountains' => array_map(fn (array $m): array => $project((float) $m[0], (float) $m[1]), $data['mountains'] ?? []),
            // Pixels for 200 km, for the scale bar: a degree of latitude is 111 km.
            'km200' => round(200 / 111 * $scale, 1),
        ];
    }

    /**
     * @param  array<array{x: float, y: float}>  $points
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

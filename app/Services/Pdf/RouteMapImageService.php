<?php

namespace App\Services\Pdf;

/**
 * Renders the trip route as a raster PNG (embedded as a base64 data URI)
 * instead of inline SVG. dompdf's inline <svg> support is unreliable — the
 * shapes silently fail to render and only the <text> nodes leak through as
 * plain flowed text, which is exactly the garbled "1 Khomas 2 Otjozondjupa
 * ..." line that used to show up in the PDF where a map should be. GD
 * output sidesteps that entirely: dompdf just embeds a normal image.
 */
class RouteMapImageService
{
    private const WIDTH = 1000;

    private const HEIGHT = 640;

    private const PADDING = 70;

    // Namibia bounding box used to normalize region lat/lng into canvas
    // space — same box the old SVG sketch used.
    private const LAT_MIN = -17.0;

    private const LAT_MAX = -30.0;

    private const LNG_MIN = 11.0;

    private const LNG_MAX = 26.0;

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<string, array{lat: float, lng: float}>  $regionCoords
     */
    public function render(array $days, array $regionCoords): ?string
    {
        $waypoints = $this->waypoints($days, $regionCoords);

        if (count($waypoints) < 2) {
            return null;
        }

        $points = $this->spreadOverlaps(array_map(fn (array $wp) => $this->project($wp), $waypoints));

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = $this->color($image, 255, 255, 255);
        $rust = $this->color($image, 192, 83, 58); // #c0533a
        $label = $this->color($image, 91, 83, 70); // #5b5346
        imagefill($image, 0, 0, $white);

        $this->drawDashedRoute($image, $points, $rust);

        $fontBold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $fontRegular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        foreach ($points as $i => $p) {
            imagefilledellipse($image, $p['x'], $p['y'], 44, 44, $white);
            imagefilledellipse($image, $p['x'], $p['y'], 40, 40, $rust);

            $num = (string) ($i + 1);
            $this->centeredText($image, $num, $p['x'], (int) ($p['y'] + 5), 15, $fontBold, $white);

            $this->centeredText($image, $waypoints[$i]['label'], $p['x'], (int) ($p['y'] + 34), 13, $fontRegular, $label);
        }

        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) $data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<string, array{lat: float, lng: float}>  $regionCoords
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    private function waypoints(array $days, array $regionCoords): array
    {
        $waypoints = [];
        $prevKey = '';

        foreach ($days as $day) {
            $key = mb_strtolower(trim((string) ($day['location'] ?? '')));

            if ($key === '' || $key === $prevKey || ! isset($regionCoords[$key])) {
                continue;
            }

            $waypoints[] = [
                'label' => $day['location'],
                'lat' => $regionCoords[$key]['lat'],
                'lng' => $regionCoords[$key]['lng'],
            ];
            $prevKey = $key;
        }

        return $waypoints;
    }

    /**
     * @param  array{label: string, lat: float, lng: float}  $waypoint
     * @return array{x: int, y: int}
     */
    private function project(array $waypoint): array
    {
        $x = self::PADDING + ($waypoint['lng'] - self::LNG_MIN) / (self::LNG_MAX - self::LNG_MIN)
            * (self::WIDTH - 2 * self::PADDING);
        $y = self::PADDING + ($waypoint['lat'] - self::LAT_MIN) / (self::LAT_MAX - self::LAT_MIN)
            * (self::HEIGHT - 2 * self::PADDING);

        return ['x' => (int) round($x), 'y' => (int) round($y)];
    }

    /**
     * A round trip revisits the same region (e.g. day 1 and the final day
     * are both "Khomas"), which projects to the exact same pixel — the
     * later circle then draws directly on top of the earlier one, hiding
     * it entirely. Nudge any point that collides with an earlier one so
     * both markers stay visible.
     *
     * @param  array<int, array{x: int, y: int}>  $points
     * @return array<int, array{x: int, y: int}>
     */
    private function spreadOverlaps(array $points): array
    {
        $seen = [];

        foreach ($points as &$point) {
            $key = "{$point['x']},{$point['y']}";
            $count = $seen[$key] ?? 0;

            if ($count > 0) {
                $point['x'] += 28 * $count;
                $point['y'] += 28 * $count;
            }

            $seen[$key] = $count + 1;
        }
        unset($point);

        return $points;
    }

    /**
     * @param  \GdImage  $image
     * @param  array<int, array{x: int, y: int}>  $points
     */
    private function drawDashedRoute($image, array $points, int $color): void
    {
        $dash = array_merge(array_fill(0, 10, $color), array_fill(0, 8, IMG_COLOR_TRANSPARENT));
        imagesetstyle($image, $dash);
        imagesetthickness($image, 3);

        for ($i = 1; $i < count($points); $i++) {
            imageline($image, $points[$i - 1]['x'], $points[$i - 1]['y'], $points[$i]['x'], $points[$i]['y'], IMG_COLOR_STYLED);
        }
    }

    /**
     * @param  \GdImage  $image
     */
    private function centeredText($image, string $text, int $centerX, int $baselineY, int $size, string $font, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box !== false ? abs($box[2] - $box[0]) : 0;
        imagettftext($image, $size, 0, (int) ($centerX - $width / 2), $baselineY, $color, $font, $text);
    }

    /**
     * @param  \GdImage  $image
     * @param  int<0, 255>  $r
     * @param  int<0, 255>  $g
     * @param  int<0, 255>  $b
     */
    private function color($image, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($image, $r, $g, $b);

        // Practically unreachable on a truecolor canvas (no palette limit to
        // exhaust) — falls back to black rather than passing `false` on.
        return $color !== false ? $color : 0;
    }
}

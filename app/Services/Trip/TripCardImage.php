<?php

namespace App\Services\Trip;

use App\Support\TripPlanMeta;
use GdImage;

/**
 * The picture a shared trip link unfurls to: the route drawn as a map, beside
 * the plan's name and how long it runs.
 *
 * A link preview is one image and two lines of text, and the text is doing the
 * naming (see TripPlanMeta) — so the image's job is to show at a glance that
 * this is *a route through Namibia*, which the generic dune photograph could
 * never do. Same visual language as public/images/og-image.png so the two read
 * as one family in a chat thread: night sky above, dunes at the foot.
 *
 * Drawn with GD rather than a headless browser on purpose. An unfurler waits a
 * couple of seconds at most and then shows nothing, so the renderer has to be
 * a function of data already in the request — no browser to start, no queue to
 * wait for. Everything is drawn at SCALE and downsampled at the end, because
 * GD antialiases neither thick lines nor ellipses.
 */
class TripCardImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Supersampling factor — the whole card is drawn this much bigger, then scaled down. */
    private const SCALE = 2;

    /** The map occupies the right of the card; the text column has the left. */
    private const MAP_LEFT = 600;

    private const MAP_TOP = 60;

    private const MAP_RIGHT = 1140;

    private const MAP_BOTTOM = 470;

    private const TEXT_LEFT = 64;

    private const TEXT_WIDTH = 470;

    /**
     * The smallest span the map will zoom to, in degrees. Without it a trip
     * with two nearby stops fills the card at street scale, which says nothing
     * — and two stops 30 km apart would look like a nation-crossing journey.
     */
    private const MIN_SPAN_DEG = 3.0;

    private const SKY = [0x1B, 0x2A, 0x4A];

    private const DUSK = [0xB9, 0x6A, 0x22];

    private const DUNE = [0x8C, 0x4A, 0x15];

    private const DUNE_DARK = [0x6B, 0x37, 0x0F];

    private const SAND = [0xD3, 0x9E, 0x56];

    private const CREAM = [0xF3, 0xE9, 0xDB];

    public function __construct(private readonly PlanWaypoints $waypoints) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return string  PNG bytes
     */
    public function render(array $plan, ?string $title, array $coords): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        imagealphablending($canvas, true);

        $this->drawBackground($canvas);
        $this->drawRoute($canvas, $this->waypoints->forDays($this->days($plan), $coords));
        $this->drawTextColumn($canvas, $plan, $title);

        $card = imagescale($canvas, self::WIDTH, self::HEIGHT, IMG_BICUBIC);

        ob_start();
        imagepng($card !== false ? $card : $canvas, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        if ($card !== false) {
            imagedestroy($card);
        }

        return $png;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function days(array $plan): array
    {
        $days = $plan['variants'][0]['days'] ?? [];

        return is_array($days) ? array_values(array_filter($days, 'is_array')) : [];
    }

    /** Night sky fading into the dunes, the same sunset the site card shows. */
    private function drawBackground(GdImage $canvas): void
    {
        $horizon = 430;
        $height = self::HEIGHT;

        for ($y = 0; $y < $height; $y++) {
            // Flat sky down to the horizon, then a short fade into the dusk
            // band the dune silhouettes sit on.
            $t = $y <= $horizon ? 0.0 : ($y - $horizon) / ($height - $horizon);
            $color = $this->mix(self::SKY, self::DUSK, $t);

            imagefilledrectangle(
                $canvas,
                0,
                $this->s($y),
                $this->s(self::WIDTH),
                $this->s($y + 1),
                $this->color($canvas, $color),
            );
        }

        $this->drawDunes($canvas, 520, self::DUNE, [0, 26, -14, 34, 4, 40]);
        $this->drawDunes($canvas, 566, self::DUNE_DARK, [22, -10, 30, -18, 26, 8]);
    }

    /**
     * One band of dunes: a polygon whose ridge line rises and falls by the
     * given offsets across the width of the card.
     *
     * @param  int[]  $offsets
     * @param  array{int, int, int}  $color
     */
    private function drawDunes(GdImage $canvas, int $baseline, array $color, array $offsets): void
    {
        $points = [0, $this->s(self::HEIGHT)];
        $steps = count($offsets);

        foreach ($offsets as $i => $offset) {
            $points[] = $this->s((int) round(self::WIDTH * $i / max(1, $steps - 1)));
            $points[] = $this->s($baseline - $offset);
        }

        $points[] = $this->s(self::WIDTH);
        $points[] = $this->s(self::HEIGHT);

        imagefilledpolygon($canvas, $points, $this->color($canvas, $color));
    }

    /**
     * @param  array<int, array{label: string, lat: float, lng: float}>  $waypoints
     */
    private function drawRoute(GdImage $canvas, array $waypoints): void
    {
        if ($waypoints === []) {
            return;
        }

        $points = $this->project($waypoints);
        $sand = $this->color($canvas, self::SAND);
        $sky = $this->color($canvas, self::SKY);
        $cream = $this->color($canvas, self::CREAM);

        // The line follows every leg, including the one home; the markers are
        // the *places*, so a trip that ends where it started draws one
        // Windhoek with the route looping back to it, not two dots sitting on
        // each other captioned twice. Numbering is by first arrival.
        $this->drawDashedRoute($canvas, $points, $sand);

        $stops = $this->stops($waypoints, $points);

        // Labels before markers, so the caption of one stop can never be drawn
        // over the dot of the next — and each is skipped when it would land on
        // a label already drawn, which is what keeps a long route legible.
        $taken = [];

        foreach ($stops as $stop) {
            $this->drawLabel($canvas, $stop['label'], $stop, $cream, $taken);
        }

        foreach ($stops as $stop) {
            imagefilledellipse($canvas, $stop['x'], $stop['y'], $this->s(30), $this->s(30), $sky);
            imagefilledellipse($canvas, $stop['x'], $stop['y'], $this->s(24), $this->s(24), $sand);

            $this->centeredText($canvas, (string) $stop['number'], $stop['x'], $stop['y'] + $this->s(5), 13, $this->bold(), $sky);
        }
    }

    /**
     * The distinct places on the route, in the order they are first reached.
     *
     * @param  array<int, array{label: string, lat: float, lng: float}>  $waypoints
     * @param  array<int, array{x: int, y: int}>  $points
     * @return array<int, array{label: string, number: int, x: int, y: int}>
     */
    private function stops(array $waypoints, array $points): array
    {
        $stops = [];
        $seen = [];

        foreach ($waypoints as $i => $waypoint) {
            $key = mb_strtolower($waypoint['label']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $stops[] = [
                'label' => $waypoint['label'],
                'number' => count($stops) + 1,
                ...$points[$i],
            ];
        }

        return $this->spreadOverlaps($stops);
    }

    /**
     * Fit the route to the map area, keeping degrees square so the shape of
     * the journey survives — a north-south run must not be stretched into a
     * fat blob just because the map area is wider than it is tall.
     *
     * @param  non-empty-array<int, array{label: string, lat: float, lng: float}>  $waypoints
     * @return array<int, array{x: int, y: int}>
     */
    private function project(array $waypoints): array
    {
        $lats = array_column($waypoints, 'lat');
        $lngs = array_column($waypoints, 'lng');

        // Room for the marker and its label at the edges of the map area.
        $left = self::MAP_LEFT + 60;
        $right = self::MAP_RIGHT - 60;
        $top = self::MAP_TOP + 20;
        $bottom = self::MAP_BOTTOM - 40;

        $centerLat = (min($lats) + max($lats)) / 2;
        $centerLng = (min($lngs) + max($lngs)) / 2;

        // Longitude degrees are shorter than latitude ones this far south, so
        // scale them before fitting or the map comes out horizontally stretched.
        $lngScale = cos(deg2rad($centerLat));

        $spanLng = max(max($lngs) - min($lngs), self::MIN_SPAN_DEG) * $lngScale;
        $spanLat = max(max($lats) - min($lats), self::MIN_SPAN_DEG);

        $pixelsPerDegree = min(($right - $left) / $spanLng, ($bottom - $top) / $spanLat);

        $centerX = ($left + $right) / 2;
        $centerY = ($top + $bottom) / 2;

        return array_map(fn (array $waypoint): array => [
            'x' => $this->s((int) round($centerX + ($waypoint['lng'] - $centerLng) * $lngScale * $pixelsPerDegree)),
            // North is up: a larger (less negative) latitude is a smaller y.
            'y' => $this->s((int) round($centerY - ($waypoint['lat'] - $centerLat) * $pixelsPerDegree)),
        ], $waypoints);
    }

    /**
     * Two different places can still round to the same pixel — a lodge and
     * the park gate beside it — and the later marker would hide the earlier
     * one. Nudge it so both stay visible.
     *
     * @param  array<int, array{label: string, number: int, x: int, y: int}>  $points
     * @return array<int, array{label: string, number: int, x: int, y: int}>
     */
    private function spreadOverlaps(array $points): array
    {
        $seen = [];

        foreach ($points as &$point) {
            $key = "{$point['x']},{$point['y']}";
            $collisions = $seen[$key] ?? 0;

            if ($collisions > 0) {
                $point['x'] += $this->s(20) * $collisions;
                $point['y'] += $this->s(20) * $collisions;
            }

            $seen[$key] = $collisions + 1;
        }
        unset($point);

        return $points;
    }

    /**
     * @param  array<int, array{x: int, y: int}>  $points
     */
    private function drawDashedRoute(GdImage $canvas, array $points, int $color): void
    {
        if (count($points) < 2) {
            return;
        }

        $dash = array_merge(
            array_fill(0, $this->s(9), $color),
            array_fill(0, $this->s(7), IMG_COLOR_TRANSPARENT),
        );

        imagesetstyle($canvas, $dash);
        imagesetthickness($canvas, $this->s(3));

        for ($i = 1; $i < count($points); $i++) {
            imageline(
                $canvas,
                $points[$i - 1]['x'], $points[$i - 1]['y'],
                $points[$i]['x'], $points[$i]['y'],
                IMG_COLOR_STYLED,
            );
        }

        imagesetthickness($canvas, 1);
    }

    /**
     * @param  array{x: int, y: int}  $point
     * @param  array<int, array{int, int, int, int}>  $taken
     */
    private function drawLabel(GdImage $canvas, string $label, array $point, int $color, array &$taken): void
    {
        $size = 15;
        $width = $this->textWidth($label, $size, $this->regular());

        // Centred on its marker, but never off the card: a long name at the
        // eastern edge of the route is pushed back inside instead of running
        // off the right of the picture.
        $x = (int) ($point['x'] - $width / 2);
        $x = max($this->s(self::MAP_LEFT - 40), min($x, $this->s(self::WIDTH - 24) - $width));

        // Below the marker by preference, above it when below is taken — two
        // stops a short drive apart would otherwise cost one of them its name.
        foreach ([$point['y'] + $this->s(34), $point['y'] - $this->s(26)] as $y) {
            // Padded, so two labels that merely touch still count as
            // colliding: a hair of clear space is what makes them readable as
            // two names rather than one long one.
            $pad = $this->s(4);
            $box = [$x - $pad, $y - $this->s(15), $x + $width + $pad, $y + $this->s(6)];

            if ($this->collides($box, $taken)) {
                continue;
            }

            $taken[] = $box;

            imagettftext($canvas, $this->s($size), 0, $x, $y, $color, $this->regular(), $label);

            return;
        }
    }

    /**
     * @param  array{int, int, int, int}  $box
     * @param  array<int, array{int, int, int, int}>  $taken
     */
    private function collides(array $box, array $taken): bool
    {
        foreach ($taken as $other) {
            if ($box[0] < $other[2] && $box[2] > $other[0] && $box[1] < $other[3] && $box[3] > $other[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function drawTextColumn(GdImage $canvas, array $plan, ?string $title): void
    {
        $sand = $this->color($canvas, self::SAND);
        $cream = $this->color($canvas, self::CREAM);
        $white = $this->color($canvas, [255, 255, 255]);
        $x = $this->s(self::TEXT_LEFT);

        $this->drawLogo($canvas, self::TEXT_LEFT, 56, 230);

        imagettftext($canvas, $this->s(15), 0, $x, $this->s(196), $sand, $this->bold(), 'SHARED TRIP PLAN');

        $y = 246;

        foreach ($this->wrap((string) ($title ?: 'Namibia trip plan'), 27, self::TEXT_WIDTH, 3) as $line) {
            imagettftext($canvas, $this->s(27), 0, $x, $this->s($y), $white, $this->bold(), $line);
            $y += 40;
        }

        if (($length = TripPlanMeta::length($plan)) !== null) {
            imagettftext($canvas, $this->s(17), 0, $x, $this->s($y + 14), $cream, $this->regular(), $length);
        }

        // Where the site card puts it too, so the two are recognisably the
        // same family of picture.
        imagettftext($canvas, $this->s(19), 0, $x, $this->s(578), $cream, $this->bold(), 'namibway.com');
    }

    private function drawLogo(GdImage $canvas, int $x, int $y, int $width): void
    {
        $logo = @imagecreatefrompng(resource_path('images/logo-light.png'));

        if ($logo === false) {
            return;
        }

        $height = (int) round($width * imagesy($logo) / imagesx($logo));

        imagecopyresampled(
            $canvas, $logo,
            $this->s($x), $this->s($y), 0, 0,
            $this->s($width), $this->s($height),
            imagesx($logo), imagesy($logo),
        );

        imagedestroy($logo);
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, int $size, int $maxWidth, int $maxLines): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if ($current !== '' && $this->textWidth($candidate, $size, $this->bold()) > $this->s($maxWidth)) {
                $lines[] = $current;
                $current = $word;

                if (count($lines) === $maxLines) {
                    break;
                }
            } else {
                $current = $candidate;
            }
        }

        if (count($lines) < $maxLines && $current !== '') {
            $lines[] = $current;
        }

        // Only the last line can have been cut short, and it has to say so.
        $overflowed = count($lines) === $maxLines
            && mb_strlen(implode(' ', $lines)) < mb_strlen(trim($text));

        if ($overflowed) {
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' ,.;:').'…';
        }

        return $lines;
    }

    private function centeredText(GdImage $canvas, string $text, int $centerX, int $baselineY, int $size, string $font, int $color): void
    {
        $width = $this->textWidth($text, $size, $font);

        imagettftext($canvas, $this->s($size), 0, (int) ($centerX - $width / 2), $baselineY, $color, $font, $text);
    }

    /** Width in canvas (scaled) pixels of the text as it will be drawn. */
    private function textWidth(string $text, int $size, string $font): int
    {
        $box = imagettfbbox($this->s($size), 0, $font, $text);

        return $box !== false ? (int) abs($box[2] - $box[0]) : 0;
    }

    /**
     * @param  array{int, int, int}  $from
     * @param  array{int, int, int}  $to
     * @return array{int, int, int}
     */
    private function mix(array $from, array $to, float $t): array
    {
        $t = max(0.0, min(1.0, $t));

        return [
            (int) round($from[0] + ($to[0] - $from[0]) * $t),
            (int) round($from[1] + ($to[1] - $from[1]) * $t),
            (int) round($from[2] + ($to[2] - $from[2]) * $t),
        ];
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function color(GdImage $canvas, array $rgb): int
    {
        /** @var int<0, 255> $r */
        $r = $rgb[0];
        /** @var int<0, 255> $g */
        $g = $rgb[1];
        /** @var int<0, 255> $b */
        $b = $rgb[2];

        $color = imagecolorallocate($canvas, $r, $g, $b);

        return $color !== false ? $color : 0;
    }

    /** Canvas pixels for a card-space measurement. */
    private function s(int|float $value): int
    {
        return (int) round($value * self::SCALE);
    }

    private function bold(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
    }

    private function regular(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    }
}

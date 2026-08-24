<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Which photograph the homepage hero wears today.
 *
 * The hero is an illustration unless config/hero.php lists photographs — see
 * that file for what a photograph has to be, and why it replaces the drawing
 * rather than layering behind it.
 *
 * The rotation is by day of the year, not at random. A visitor who comes back
 * tomorrow gets a different Namibia; one who reloads twice in an afternoon
 * gets the same page they were looking at, which is what makes it feel
 * curated rather than shuffled. Cycling in order also guarantees every photo
 * in the set is actually seen — five photos picked at random are five photos
 * of which two carry most of the impressions. Same reasoning, and the same
 * once-a-day cadence, as the homepage's listing picks (HomeController).
 */
class HeroPhoto
{
    /**
     * The photo to show on $day, or null when none are configured — the
     * supported "hero stays illustrated" state, not a failure.
     *
     * $prefer names a slug to show instead of today's, which is what the
     * `?hero=` query parameter is for: showing somebody a specific photo
     * without waiting for its turn. An unknown slug falls through to the
     * day's photo rather than erroring — it is a preview aid, not an API.
     *
     * @return array{slug: string, url: string, credit: string|null, focus: string}|null
     */
    public static function forDay(CarbonInterface $day, ?string $prefer = null): ?array
    {
        $photos = array_values(array_filter(
            (array) config('hero.photos', []),
            fn (mixed $photo): bool => is_array($photo) && filled($photo['file'] ?? null),
        ));

        if ($photos === []) {
            return null;
        }

        $chosen = null;

        if ($prefer !== null) {
            foreach ($photos as $photo) {
                if (($photo['slug'] ?? null) === $prefer) {
                    $chosen = $photo;
                    break;
                }
            }
        }

        $chosen ??= $photos[$day->dayOfYear % count($photos)];

        return [
            'slug' => (string) ($chosen['slug'] ?? ''),
            'url' => self::url((string) $chosen['file']),
            'credit' => filled($chosen['credit'] ?? null) ? (string) $chosen['credit'] : null,
            'focus' => filled($chosen['focus'] ?? null) ? (string) $chosen['focus'] : '50% 50%',
        ];
    }

    /**
     * A configured file is a path under public/ — but a photo big enough for
     * a full-bleed hero is exactly the kind of file that ends up on R2
     * instead, so a full URL is passed through untouched.
     */
    private static function url(string $file): string
    {
        return str_starts_with($file, 'http://') || str_starts_with($file, 'https://')
            ? $file
            : asset(ltrim($file, '/'));
    }
}

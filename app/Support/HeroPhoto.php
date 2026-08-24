<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Which face the homepage hero wears today.
 *
 * The hero rotates through the photographs in config/hero.php and — when
 * `include_illustration` is on — through the original drawing as well, which
 * is what a null return means. See that file for what a photograph has to be,
 * and why a photograph replaces the drawing rather than layering behind it.
 *
 * The rotation is by day of the year, not at random. A visitor who comes back
 * tomorrow gets a different Namibia; one who reloads twice in an afternoon
 * gets the same page they were looking at, which is what makes it feel
 * curated rather than shuffled. Cycling in order also guarantees every slot
 * in the set is actually seen — five photos picked at random are five photos
 * of which two carry most of the impressions. Same reasoning, and the same
 * once-a-day cadence, as the homepage's listing picks (HomeController).
 */
class HeroPhoto
{
    /**
     * The slug that names the drawing rather than a photograph. Only ever
     * used to preview it — a rotation slot for the drawing is a null entry,
     * not a record with a name.
     */
    public const ILLUSTRATION = 'illustration';

    /**
     * The photo to show on $day, or null for the drawing — which is both a
     * rotation slot and the state of a site with no photographs configured.
     *
     * $prefer names a slug to show instead of today's, which is what the
     * `?hero=` query parameter is for: showing somebody a specific one
     * without waiting for its turn. An unknown slug falls through to the
     * day's slot rather than erroring — it is a preview aid, not an API.
     *
     * @return array{slug: string, url: string, credit: string|null, focus: string, scrim: string}|null
     */
    public static function forDay(CarbonInterface $day, ?string $prefer = null): ?array
    {
        if ($prefer === self::ILLUSTRATION) {
            return null;
        }

        $photos = self::configured();

        if ($photos === []) {
            return null;
        }

        if ($prefer !== null) {
            foreach ($photos as $photo) {
                if (($photo['slug'] ?? null) === $prefer) {
                    return self::present($photo);
                }
            }
        }

        // The drawing takes part in the rotation as a slot with nothing in
        // it, so "today is a photo" and "today is the drawing" are the same
        // question asked once rather than two separate decisions.
        $slots = $photos;

        if (config('hero.include_illustration', false)) {
            $slots[] = null;
        }

        $slot = $slots[$day->dayOfYear % count($slots)];

        return $slot === null ? null : self::present($slot);
    }

    /**
     * Every configured photograph's provenance, in configured order — who
     * took it, under what licence, and where it can be checked.
     *
     * This is what the credits section of /legal is rendered from
     * (App\Support\LegalNotice), so the page names exactly the photographs
     * the site can actually show. A credits list maintained beside the
     * photographs rather than from them is a credits list that goes stale the
     * first time somebody swaps a file.
     *
     * @return list<array{slug: string, title: string, photographer: string|null, license: string|null, source: string|null}>
     */
    public static function credits(): array
    {
        return array_map(fn (array $photo): array => [
            'slug' => (string) ($photo['slug'] ?? ''),
            'title' => (string) ($photo['title'] ?? ($photo['slug'] ?? '')),
            'photographer' => filled($photo['photographer'] ?? null) ? (string) $photo['photographer'] : null,
            'license' => filled($photo['license'] ?? null) ? (string) $photo['license'] : null,
            'source' => filled($photo['source'] ?? null) ? (string) $photo['source'] : null,
        ], self::configured());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function configured(): array
    {
        return array_values(array_filter(
            (array) config('hero.photos', []),
            fn (mixed $photo): bool => is_array($photo) && filled($photo['file'] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $photo
     * @return array{slug: string, url: string, credit: string|null, focus: string, scrim: string}
     */
    private static function present(array $photo): array
    {
        return [
            'slug' => (string) ($photo['slug'] ?? ''),
            'url' => self::url((string) $photo['file']),
            'credit' => self::heroCredit($photo),
            'focus' => filled($photo['focus'] ?? null) ? (string) $photo['focus'] : '50% 50%',
            'scrim' => ($photo['scrim'] ?? null) === 'light' ? 'light' : 'strong',
        ];
    }

    /**
     * The line printed over the hero itself, or null for no line at all.
     *
     * Composed from the same photographer and licence /legal is built from
     * rather than typed a second time, so the two can never disagree about
     * who took a picture. /legal credits every photograph; this is only about
     * the ones whose licence requires the credit to travel with the image.
     *
     * @param  array<string, mixed>  $photo
     */
    private static function heroCredit(array $photo): ?string
    {
        if (($photo['credit_on_hero'] ?? false) !== true) {
            return null;
        }

        $parts = array_values(array_filter([
            filled($photo['photographer'] ?? null) ? (string) $photo['photographer'] : null,
            filled($photo['license'] ?? null) ? (string) $photo['license'] : null,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
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

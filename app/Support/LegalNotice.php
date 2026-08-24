<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What /legal says: who operates the site, and whose pictures are on it.
 *
 * The same rule the customer-website legal texts are written under
 * (App\Sites\LegalText) applies here, and it is the load-bearing one: this
 * writes no legal wording and gives no advice. What it assembles is a factual
 * description of how this site works, plus facts about the company that a
 * person put into config/legal.php. Nothing here invents an address, a
 * register entry or a licence.
 *
 * The credits are *rendered from the photographs themselves*, not maintained
 * beside them — config/hero.php is the one place a hero photo's provenance is
 * written down, and the page reads it. A credits list kept in parallel with
 * the images is a credits list that is wrong the first time somebody swaps a
 * file, which is the failure mode that makes a credits page worse than none.
 */
class LegalNotice
{
    /**
     * The operator block, or null when nobody has filled it in — which the
     * page renders as an explicit "still to come" rather than as a legal
     * notice that happens to be missing its middle.
     *
     * @return array{name: string, legal_form: string|null, address: list<string>, country: string|null, represented_by: list<string>, register: string|null, registration_number: string|null, vat_id: string|null, email: string|null, phone: string|null, content_responsible: string|null, notes: string|null}|null
     */
    public static function operator(): ?array
    {
        /** @var array<string, mixed> $operator */
        $operator = (array) config('legal.operator', []);

        if (blank($operator['name'] ?? null)) {
            return null;
        }

        return [
            'name' => (string) $operator['name'],
            'legal_form' => self::text($operator['legal_form'] ?? null),
            'address' => self::lines($operator['address'] ?? null),
            'country' => self::text($operator['country'] ?? null),
            'represented_by' => self::lines($operator['represented_by'] ?? null),
            'register' => self::text($operator['register'] ?? null),
            'registration_number' => self::text($operator['registration_number'] ?? null),
            'vat_id' => self::text($operator['vat_id'] ?? null),
            'email' => self::text($operator['email'] ?? null),
            'phone' => self::text($operator['phone'] ?? null),
            'content_responsible' => self::text($operator['content_responsible'] ?? null),
            'notes' => self::text($operator['notes'] ?? null),
        ];
    }

    /**
     * The © line's holder and year. The year is today's, not a stored one:
     * a hardcoded year is a chore nobody remembers every January.
     *
     * @return array{holder: string, year: int}
     */
    public static function copyright(): array
    {
        return [
            'holder' => (string) (config('legal.copyright_holder') ?: config('app.name')),
            'year' => Carbon::now()->year,
        ];
    }

    /**
     * Every picture on the site whose provenance we hold: the rotating hero
     * photographs first (read straight out of config/hero.php), then whatever
     * else has been recorded by hand.
     *
     * @return list<array{title: string, photographer: string|null, license: string|null, source: string|null}>
     */
    public static function imageCredits(): array
    {
        $hero = array_map(fn (array $photo): array => [
            'title' => $photo['title'],
            'photographer' => $photo['photographer'],
            'license' => $photo['license'],
            'source' => $photo['source'],
        ], HeroPhoto::credits());

        $extra = [];

        foreach ((array) config('legal.image_credits', []) as $credit) {
            if (! is_array($credit) || blank($credit['title'] ?? null)) {
                continue;
            }

            $extra[] = [
                'title' => (string) $credit['title'],
                'photographer' => self::text($credit['photographer'] ?? null),
                'license' => self::text($credit['license'] ?? null),
                'source' => self::text($credit['source'] ?? null),
            ];
        }

        return [...$hero, ...$extra];
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) && (is_string($value) || is_numeric($value))
            ? trim((string) $value)
            : null;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $line): ?string => self::text($line),
            $value,
        )));
    }
}

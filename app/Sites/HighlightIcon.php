<?php

namespace App\Sites;

/**
 * A line-drawn mark for a highlight, chosen from the words it is made of.
 *
 * Highlights arrive as short phrases — a lodge's own ("Dune walks at first
 * light") or, failing that, its amenity list ("Wi-Fi", "Swimming pool"). They
 * render as a column of headings, which reads as a list of tags rather than as
 * a feature of the page, and a small mark above each one is what turns the
 * second into the first.
 *
 * ## Two rules that keep this from going wrong
 *
 * **No icon is better than the wrong icon.** Nothing matches, nothing is drawn,
 * and the card falls back to exactly what it renders today. A generic star or
 * tick beside "Trophy hunting" says nothing and costs the whole set its
 * credibility.
 *
 * **Matching is on whole words.** "bar" inside "harbour" and "spa" inside
 * "space" are the reason: every pattern is anchored, so a mark is only chosen
 * when the word is really there.
 *
 * The marks themselves live in resources/views/sites/partials/icon.blade.php,
 * one 24px stroke path each, drawn in `currentColor` so the site's own accent
 * is the only colour on them — see the note there about why they are inline.
 */
class HighlightIcon
{
    /**
     * Icon key => the words that choose it.
     *
     * Ordered: the first match wins, so anything specific has to come before
     * the general thing it contains. "Swimming pool bar" is a bar.
     *
     * @return array<string, array<int, string>>
     */
    private static function map(): array
    {
        return [
            'wifi' => ['wi-?fi', 'internet', 'wireless'],
            'pool' => ['pool', 'swimming'],
            // "self-catering" is a kind of accommodation, not a kitchen that
            // cooks for you, so it is kept out of this one and listed under
            // 'bed' — which is further down, hence the lookbehind rather than
            // an ordering trick.
            'restaurant' => [
                'restaurant', 'dining', 'dinner', 'lunch', '(?<!self-)(?<!self )catering',
                'meals?', 'cuisine', 'kitchen', 'buffet', 'a ?la carte',
            ],
            'breakfast' => ['breakfast', 'coffee', 'tea'],
            'bar' => ['bar', 'drinks?', 'wine', 'sundowners?'],
            'fire' => ['braai', 'barbecue', 'bbq', 'boma', 'lapa', 'fire ?place', 'fireside', 'camp ?fires?'],
            // Before 'bed', so "family rooms" is about who it is for rather
            // than what is in it.
            'family' => ['family', 'families', 'children', 'kids', 'child-?friendly'],
            // Camping is its own kind of stay, and before 'bed' because a
            // "tented camp" has beds in it.
            'tent' => ['camping', 'camp ?sites?', 'tents?', 'tented', 'glamping'],
            'bed' => [
                'lodges?', 'guest ?houses?', 'rooms?', 'suites?', 'chalets?',
                'bungalows?', 'cottages?', 'villas?', 'apartments?',
                'self[ -]?catering', 'accommodation', 'en-?suite', 'beds?',
            ],
            'shower' => ['bathrooms?', 'showers?', 'bath'],
            'snowflake' => ['air ?con\w*', 'a/c', 'climate', 'cooling'],
            'car' => ['parking', 'garage', 'transfers?', 'shuttle', 'airport'],
            'jeep' => ['4x4', 'game drives?', 'safaris?', 'self-?drive', 'vehicles?'],
            'binoculars' => ['wildlife', 'game viewing', 'birding', 'bird ?watching', 'animals?'],
            'boot' => ['walks?', 'walking', 'hiking', 'trails?', 'guided walks?'],
            'camera' => ['photograph\w*', 'photo ?safari', 'hide'],
            'star' => ['stargazing', 'night sky', 'astronom\w*'],
            'sun' => ['sunsets?', 'sunrise', 'views?', 'scenic'],
            'tree' => ['gardens?', 'bush', 'nature', 'reserve', 'farm'],
            'water' => ['waterhole', 'river', 'spring', 'lake', 'dam'],
            'spa' => ['spa', 'massage', 'wellness', 'sauna'],
            'pet' => ['pets?', 'dogs?', 'pet-?friendly'],
            'shield' => ['secure', 'security', 'safe', 'insured', 'licen[cs]ed'],
            'clock' => ['24 ?hours?', 'open daily', 'all day'],
            'card' => ['credit cards?', 'payments?', 'eft'],
        ];
    }

    /**
     * Every mark this can choose.
     *
     * Exposed so a test can hold the list against the partial that draws them:
     * a key here with no `@case` there is an empty `<svg>` on somebody's page.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::map());
    }

    /**
     * The mark for this phrase, or null when nothing in it is recognised.
     */
    public static function for(string $title): ?string
    {
        foreach (self::map() as $icon => $patterns) {
            foreach ($patterns as $pattern) {
                // Delimited with ~ rather than /, because a pattern here can
                // legitimately contain a slash (a/c).
                if (preg_match('~\b(?:'.$pattern.')\b~iu', $title) === 1) {
                    return $icon;
                }
            }
        }

        return null;
    }

    /**
     * Whether a set of highlights is worth drawing marks for at all.
     *
     * One recognised phrase out of six is not a design, it is a stray picture.
     * Either most of the column carries a mark or none of it does — which is
     * also what stops a scraped amenity list producing a ragged half-set.
     *
     * @param  array<int, string>  $titles
     */
    public static function worthDrawing(array $titles): bool
    {
        if ($titles === []) {
            return false;
        }

        $matched = count(array_filter($titles, fn (string $title): bool => self::for($title) !== null));

        return $matched * 2 >= count($titles);
    }
}

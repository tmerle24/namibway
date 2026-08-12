<?php

namespace App\Sites;

use App\Enums\BusinessType;

/**
 * The line set in the largest type on the page.
 *
 * ## Why it is not the business's name
 *
 * It was, and that put the name twice on the first screen — once in the bar and
 * once four lines below it in display type. On a generated draft shown to a
 * prospect that reads as a bug rather than as emphasis. The bar is where a
 * visitor looks to find out whose site they are on, so the bar keeps the name
 * and the hero gets something to say.
 *
 * ## Why these lines and not a written slogan
 *
 * A slogan is the business's to write and we do not know theirs. What is here
 * is one step back from that: a short line true of the whole category, so a
 * lodge that has told us nothing still opens with something rather than with
 * its own name repeated. Every one of them is meant to be replaced — the
 * Website tab edits this, the subline and whether the town shows at all.
 *
 * ## Why the choice is a hash and not random
 *
 * Two lodges next to each other opening on the same words looks like the
 * template it is, so there is more than one line per category. But a draft that
 * says something different every time it is rebuilt is not a draft anybody can
 * approve, and the generator keeps edits by comparing what it wrote last time.
 * So the pick is a function of the slug: stable for a site, spread across a
 * list of them.
 */
class HeroLines
{
    /**
     * @return array<string, array<int, string>>
     */
    private static function lines(): array
    {
        return [
            BusinessType::Accommodation->value => [
                'Stay a while',
                'Room to breathe',
                'Somewhere to come back to',
                'The quiet part of the journey',
            ],
            BusinessType::Restaurant->value => [
                'Come hungry',
                'Pull up a chair',
                'Cooked today, eaten today',
                'A table is waiting',
            ],
            BusinessType::Activity->value => [
                'The adventure starts here',
                'Go and see for yourself',
                'Out there, with us',
                'Worth getting up for',
            ],
            BusinessType::TourOperator->value => [
                'We know the way',
                'Namibia, properly seen',
                'Let somebody else drive',
                'The route is the easy part',
            ],
            BusinessType::CarRental->value => [
                'Keys, and a full tank',
                'Go where the tar stops',
                'Your own pace, your own road',
                'Built for the gravel',
            ],
            BusinessType::Retail->value => [
                'Come and have a look',
                'Worth the trip into town',
                'Everything you came for',
            ],
            BusinessType::Service->value => [
                'How can we help?',
                'Done properly, first time',
                'We will sort it out',
            ],
        ];
    }

    /**
     * A stable opening line for this business — never its own name.
     */
    public static function for(BusinessType $type, string $slug): string
    {
        $lines = self::lines()[$type->value] ?? self::lines()[BusinessType::Service->value];

        // crc32 rather than a random pick: the same site gets the same line on
        // every rebuild, which is what lets the generator tell "we wrote this"
        // from "somebody edited it".
        return $lines[crc32($slug) % count($lines)];
    }
}

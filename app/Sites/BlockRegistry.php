<?php

namespace App\Sites;

use App\Enums\BusinessType;
use App\Sites\Blocks\AboutBlock;
use App\Sites\Blocks\BlockDefinition;
use App\Sites\Blocks\BookingBlock;
use App\Sites\Blocks\ContactBlock;
use App\Sites\Blocks\CtaBlock;
use App\Sites\Blocks\FooterBlock;
use App\Sites\Blocks\GalleryBlock;
use App\Sites\Blocks\HeroBlock;
use App\Sites\Blocks\HighlightsBlock;
use App\Sites\Blocks\LocationBlock;
use App\Sites\Blocks\OpeningHoursBlock;
use App\Sites\Blocks\PriceListBlock;
use App\Sites\Blocks\RichTextBlock;

/**
 * The whole block library, and the order each kind of business starts in.
 *
 * Twelve types. That number is a commitment, not a stage: the offer works
 * because one kit serves every customer, and the first per-customer block is
 * the moment the margin starts leaking. A new type has to earn its place across
 * several customers, and when it does it costs a class and a line here — never
 * a migration.
 */
class BlockRegistry
{
    /** @var array<int, class-string<BlockDefinition>> */
    private const TYPES = [
        HeroBlock::class,
        HighlightsBlock::class,
        AboutBlock::class,
        GalleryBlock::class,
        OpeningHoursBlock::class,
        PriceListBlock::class,
        BookingBlock::class,
        RichTextBlock::class,
        LocationBlock::class,
        ContactBlock::class,
        CtaBlock::class,
        FooterBlock::class,
    ];

    /**
     * The order a generated site starts in, per business type.
     *
     * These follow the patterns the market already uses — a lodge leads with
     * the place and its rooms, a restaurant with the food and when it is open,
     * a workshop with what it does and how to reach it. Nothing experimental:
     * visitors know these shapes, and a website that surprises them is a
     * website that loses them.
     *
     * `booking` appears wherever it could apply. The renderer drops it where
     * the property has nothing sellable, so listing it here costs nothing and
     * means a lodge that enters its rooms next month gets the block without
     * anybody rebuilding the page.
     *
     * @var array<string, array<int, string>>
     */
    private const LAYOUTS = [
        'accommodation' => ['hero', 'highlights', 'about', 'gallery', 'booking', 'location', 'contact', 'footer'],
        'restaurant' => ['hero', 'about', 'opening_hours', 'price_list', 'gallery', 'location', 'contact', 'footer'],
        'activity' => ['hero', 'highlights', 'about', 'gallery', 'price_list', 'booking', 'location', 'contact', 'footer'],
        'car_rental' => ['hero', 'highlights', 'price_list', 'about', 'booking', 'location', 'contact', 'footer'],
        'tour_operator' => ['hero', 'highlights', 'about', 'gallery', 'price_list', 'booking', 'contact', 'footer'],
        'retail' => ['hero', 'about', 'opening_hours', 'gallery', 'price_list', 'location', 'contact', 'footer'],
        'service' => ['hero', 'highlights', 'about', 'opening_hours', 'price_list', 'location', 'contact', 'footer'],
    ];

    /** @var array<string, BlockDefinition>|null */
    private static ?array $resolved = null;

    /**
     * Every block type, keyed by its stored `type` string.
     *
     * @return array<string, BlockDefinition>
     */
    public static function all(): array
    {
        if (self::$resolved === null) {
            $resolved = [];

            foreach (self::TYPES as $class) {
                $definition = new $class;
                $resolved[$definition->type()] = $definition;
            }

            self::$resolved = $resolved;
        }

        return self::$resolved;
    }

    /**
     * The definition for a stored type, or null where the type is not in the
     * library any more.
     *
     * Null is a real answer rather than an exception: a withdrawn type leaves
     * rows behind on live customer sites, and those pages must keep serving
     * without that block rather than 500 on a class that no longer exists.
     */
    public static function find(string $type): ?BlockDefinition
    {
        return self::all()[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return self::find($type) !== null;
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    /**
     * The block order a site of this kind is generated with.
     *
     * @return array<int, string>
     */
    public static function layoutFor(BusinessType $type): array
    {
        return self::LAYOUTS[$type->value] ?? self::LAYOUTS['service'];
    }
}

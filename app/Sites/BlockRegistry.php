<?php

namespace App\Sites;

use App\Enums\BusinessType;
use App\Sites\Blocks\AboutBlock;
use App\Sites\Blocks\BlockDefinition;
use App\Sites\Blocks\BookingBlock;
use App\Sites\Blocks\ContactBlock;
use App\Sites\Blocks\CtaBlock;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\FaqBlock;
use App\Sites\Blocks\FooterBlock;
use App\Sites\Blocks\GalleryBlock;
use App\Sites\Blocks\HeroBlock;
use App\Sites\Blocks\HighlightsBlock;
use App\Sites\Blocks\ItineraryBlock;
use App\Sites\Blocks\LocationBlock;
use App\Sites\Blocks\MissionBlock;
use App\Sites\Blocks\OffersBlock;
use App\Sites\Blocks\OpeningHoursBlock;
use App\Sites\Blocks\PhotoBandBlock;
use App\Sites\Blocks\PriceListBlock;
use App\Sites\Blocks\RichTextBlock;
use App\Sites\Blocks\ShopBlock;
use App\Sites\Blocks\TeamBlock;
use App\Sites\Blocks\TestimonialsBlock;
use App\Sites\Blocks\VideoBlock;
use App\Sites\Blocks\WhyChooseUsBlock;

/**
 * The whole block library, and the order each kind of business starts in.
 *
 * Small on purpose: the offer works because one kit serves every customer, and
 * the first per-customer block is the moment the margin starts leaking. A new
 * type has to earn its place across several customers, and when it does it
 * costs a class and a line here — never a migration.
 *
 * 2026-09-10: offers, itinerary, team, testimonials, FAQ, video and the
 * photo band joined, after the first real customer (a tour operator) could
 * not be shown a site worth paying for with the sixteen before them. None is
 * specific to that customer — each serves a lodge, an activity or a workshop
 * as well.
 */
class BlockRegistry
{
    /** @var array<int, class-string<BlockDefinition>> */
    private const TYPES = [
        HeroBlock::class,
        HighlightsBlock::class,
        AboutBlock::class,
        OffersBlock::class,
        ItineraryBlock::class,
        PhotoBandBlock::class,
        GalleryBlock::class,
        VideoBlock::class,
        TeamBlock::class,
        OpeningHoursBlock::class,
        PriceListBlock::class,
        BookingBlock::class,
        TestimonialsBlock::class,
        FaqBlock::class,
        EnquiryBlock::class,
        RichTextBlock::class,
        MissionBlock::class,
        ShopBlock::class,
        WhyChooseUsBlock::class,
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
        'accommodation' => ['hero', 'highlights', 'about', 'photo_band', 'gallery', 'video', 'booking', 'testimonials', 'faq', 'enquiry', 'location', 'contact', 'footer'],
        'restaurant' => ['hero', 'about', 'opening_hours', 'price_list', 'photo_band', 'gallery', 'testimonials', 'enquiry', 'location', 'contact', 'footer'],
        'activity' => ['hero', 'highlights', 'about', 'offers', 'itinerary', 'photo_band', 'gallery', 'video', 'team', 'price_list', 'booking', 'testimonials', 'faq', 'enquiry', 'location', 'contact', 'footer'],
        'car_rental' => ['hero', 'highlights', 'price_list', 'about', 'booking', 'testimonials', 'faq', 'enquiry', 'location', 'contact', 'footer'],
        'tour_operator' => ['hero', 'highlights', 'about', 'offers', 'itinerary', 'photo_band', 'gallery', 'video', 'team', 'price_list', 'booking', 'testimonials', 'faq', 'enquiry', 'contact', 'footer'],
        'retail' => ['hero', 'about', 'mission', 'why_choose_us', 'shop', 'opening_hours', 'gallery', 'price_list', 'testimonials', 'enquiry', 'location', 'contact', 'footer'],
        'service' => ['hero', 'highlights', 'about', 'offers', 'mission', 'why_choose_us', 'team', 'opening_hours', 'price_list', 'testimonials', 'faq', 'enquiry', 'location', 'contact', 'footer'],
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
        // No fallback. Every business type has a layout, and a new one added
        // without one should fail here — loudly, at analysis time — rather than
        // quietly serving somebody's car rental the layout for a plumber.
        return self::LAYOUTS[$type->value];
    }
}

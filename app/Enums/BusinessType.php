<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of business a website is for.
 *
 * Deliberately wider than `ListingType`, and deliberately not derived from it.
 * A listing is something a traveller can find and book on the travel platform;
 * this is anybody who buys a website, and half of them — the workshop, the
 * hardware shop, the accountant — will never be on the platform at all. Sharing
 * one enum would either force those businesses to masquerade as a listing type
 * or push non-bookable cases into the travel domain's vocabulary.
 *
 * The type chooses the starting block order and the accent. Nothing else hangs
 * off it, which is why adding one is cheap.
 */
enum BusinessType: string implements HasColor, HasLabel
{
    case Accommodation = 'accommodation';
    case Restaurant = 'restaurant';
    case Activity = 'activity';
    case CarRental = 'car_rental';
    case TourOperator = 'tour_operator';
    case Retail = 'retail';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accommodation => 'Accommodation',
            self::Restaurant => 'Restaurant',
            self::Activity => 'Activity provider',
            self::CarRental => 'Car rental',
            self::TourOperator => 'Tour operator',
            self::Retail => 'Shop',
            self::Service => 'Service business',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accommodation => 'info',
            self::Restaurant => 'warning',
            self::Activity, self::TourOperator => 'success',
            self::CarRental => 'primary',
            self::Retail, self::Service => 'gray',
        };
    }

    /**
     * The nearest business type for a listing we are generating from.
     *
     * `Vehicle` splits two ways on the travel platform — self-drive hire and
     * guided tours — and they are different businesses with different websites,
     * so the caller resolves that from `vehicle_category` and only falls back
     * here.
     */
    public static function fromListingType(ListingType $type): self
    {
        return match ($type) {
            ListingType::Accommodation => self::Accommodation,
            ListingType::Restaurant => self::Restaurant,
            ListingType::Activity => self::Activity,
            ListingType::Vehicle => self::CarRental,
        };
    }

    /**
     * Whether a property of this kind sells something with dates and prices
     * behind it. Only a "yes" can carry a booking block, and even then only
     * where the booking system actually holds that property's inventory.
     */
    public function isBookable(): bool
    {
        return match ($this) {
            self::Accommodation, self::Activity, self::CarRental, self::TourOperator => true,
            self::Restaurant, self::Retail, self::Service => false,
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of place a `places` row is.
 *
 * A place is a *tourism location* — where a traveler goes and where a lodge
 * stands when it stands nowhere near a town. That is a different thing from a
 * city, which is an *address*: Onguma has no street and no postcode, and
 * Windhoek is not somewhere you drive to see.
 *
 * Between 2026-08-18 and 2026-08-23 these three cases lived on `cities`
 * alongside the settlements, which put a national park in a table called
 * cities and made "which city is this lodge in?" unanswerable. The settlement
 * cases moved to CityType; what is left here is only the second kind.
 */
enum PlaceType: string implements HasColor, HasLabel
{
    case NationalPark = 'national_park';
    case NatureReserve = 'nature_reserve';
    case Landmark = 'landmark';

    public function getLabel(): string
    {
        return match ($this) {
            self::NationalPark => 'National park',
            self::NatureReserve => 'Nature reserve / private game reserve',
            self::Landmark => 'Landmark / tourism area',
        };
    }

    public function getColor(): string
    {
        return 'primary';
    }

    /**
     * The stored value of every case, for validation and column checks.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

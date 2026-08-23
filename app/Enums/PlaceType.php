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

    /**
     * A town a traveler plans around — Windhoek, Swakopmund, Sesriem.
     *
     * A place of this kind mirrors a city, and that is the point rather than a
     * duplication: the city row is the *address* (population, area, what a
     * street address resolves to), this row is the *stop on a trip*. Keeping
     * both means everything Kaia routes on is a place, so the driving matrix
     * stays place-to-place instead of becoming a polymorphic city-or-place
     * pair, and `cities.place_id` is the link between the two identities.
     */
    case Town = 'town';

    public function getLabel(): string
    {
        return match ($this) {
            self::NationalPark => 'National park',
            self::NatureReserve => 'Nature reserve / private game reserve',
            self::Landmark => 'Landmark / tourism area',
            self::Town => 'Town',
        };
    }

    public function getColor(): string
    {
        return $this === self::Town ? 'success' : 'primary';
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

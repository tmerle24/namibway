<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of city row this is.
 *
 * Was SettlementType, then PlaceType (2026-08-18, when parks and reserves were
 * given rows in `cities` so a lodge standing in one had somewhere to be filed).
 * That conflated two different things: a city is an *address*, and a park is a
 * *tourism location*. Since 2026-08-23 the second kind lives in `places` and
 * this enum goes back to classifying settlements only.
 *
 * The three tourism cases are still here because `cities` still holds those
 * rows until the readers are repointed — step 3 of that move deletes them.
 */
enum CityType: string implements HasColor, HasLabel
{
    case Municipality = 'municipality';
    case Town = 'town';
    case Community = 'community';
    case Village = 'village';
    case Settlement = 'settlement';
    case PrivateTown = 'private_town';
    case NationalPark = 'national_park';
    case NatureReserve = 'nature_reserve';
    case Landmark = 'landmark';

    public function getLabel(): string
    {
        return match ($this) {
            self::Municipality => 'Municipality (Stadtgemeinde)',
            self::Town => 'Town (Stadt)',
            self::Community => 'Community (Gemeinde)',
            self::Village => 'Village (Dorf)',
            self::Settlement => 'Settlement (Siedlung)',
            self::PrivateTown => 'Private town (Privatstadt)',
            self::NationalPark => 'National park',
            self::NatureReserve => 'Nature reserve / private game reserve',
            self::Landmark => 'Landmark / tourism area',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Municipality => 'info',
            self::Town => 'success',
            self::Community => 'warning',
            self::Village => 'gray',
            self::Settlement => 'gray',
            self::PrivateTown => 'danger',
            self::NationalPark, self::NatureReserve, self::Landmark => 'primary',
        };
    }

    /**
     * Whether people live here, as opposed to a tourism area named for what is
     * there rather than for who lives there. Population and area are only ever
     * meaningful for the first kind.
     */
    public function isSettlement(): bool
    {
        return match ($this) {
            self::NationalPark, self::NatureReserve, self::Landmark => false,
            default => true,
        };
    }

    /**
     * Whether a place of this kind earns a row in the city-to-city driving
     * matrix Kaia's daily-drive check used to read; since 2026-08-23 that matrix
     * is place-to-place and this method only decides which cities earn a place
     * row (see give_trip_relevant_cities_a_place).
     *
     * The larger settlements are in because a lodge can plausibly sit there;
     * every tourism area is in for the same reason and more strongly — a park
     * or a reserve exists in this table precisely because something bookable
     * stands on it. The small villages and settlements stay out: they are the
     * bulk of the gazetteer, almost none of them will ever host a listing, and
     * anything outside the matrix still falls back to the region-level table.
     */
    public function inDrivingMatrix(): bool
    {
        return match ($this) {
            self::Municipality, self::Town, self::Community, self::NationalPark, self::NatureReserve, self::Landmark => true,
            self::Village, self::Settlement, self::PrivateTown => false,
        };
    }

    /**
     * The stored values of every type in the driving matrix, for querying.
     *
     * @return array<int, string>
     */
    public static function drivingMatrixValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->inDrivingMatrix()),
        ));
    }
}

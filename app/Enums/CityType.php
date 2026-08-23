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
 * this enum classifies settlements only — every case here is somewhere people
 * live, which is why there is no isSettlement() any more.
 */
enum CityType: string implements HasColor, HasLabel
{
    case Municipality = 'municipality';
    case Town = 'town';
    case Community = 'community';
    case Village = 'village';
    case Settlement = 'settlement';
    case PrivateTown = 'private_town';

    public function getLabel(): string
    {
        return match ($this) {
            self::Municipality => 'Municipality (Stadtgemeinde)',
            self::Town => 'Town (Stadt)',
            self::Community => 'Community (Gemeinde)',
            self::Village => 'Village (Dorf)',
            self::Settlement => 'Settlement (Siedlung)',
            self::PrivateTown => 'Private town (Privatstadt)',
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
        };
    }

    /**
     * Whether a settlement of this kind is big enough to be a stop on a trip
     * — i.e. to earn a `places` row of its own and with it a place in the
     * driving matrix Kaia's daily-drive check reads.
     *
     * The larger settlements are in because a lodge can plausibly sit there.
     * The villages, settlements and private towns stay out: they are the bulk
     * of the gazetteer, almost none of them will ever host a listing, and a
     * leg between places outside the matrix still falls back to the
     * region-level table, so nothing is left unvalidated.
     *
     * Parks, reserves and landmarks used to be answered here too. They are
     * places now and every place is in the matrix unconditionally, which is
     * why this question is only about settlements.
     */
    public function inDrivingMatrix(): bool
    {
        return match ($this) {
            self::Municipality, self::Town, self::Community => true,
            self::Village, self::Settlement, self::PrivateTown => false,
        };
    }

    /**
     * The stored values of every settlement type that earns a place, for querying.
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

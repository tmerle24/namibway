<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a listing's `price_from` is actually quoted per.
 *
 * Without this the number is unreadable: a lodge rate in the Southern African
 * market is just as often "per person sharing" as it is per room, and an
 * activity's rate is just as often per group as per traveler — so a plan that
 * adds those up is adding numbers that don't mean the same thing. Two
 * dimensions are folded into one enum rather than two columns, because those
 * are the only combinations that occur and a single select is one decision for
 * the partner entering it instead of two:
 *
 *  - the period it repeats over — a night, a day, or one-off, and
 *  - whether it is per traveler or for the whole party/room/vehicle.
 *
 * A null column means "not recorded", which is where every existing row starts.
 * Nothing guesses a value: the trip plan prints an unqualified price exactly as
 * it does today and counts it once, rather than inventing a per-person claim it
 * can't back (see resources/js/lib/price-unit.ts for the display side).
 */
enum PriceUnit: string implements HasLabel
{
    case PerNight = 'per_night';
    case PerPersonPerNight = 'per_person_per_night';
    case PerDay = 'per_day';
    case PerPersonPerDay = 'per_person_per_day';
    case PerPerson = 'per_person';
    case PerBooking = 'per_booking';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerNight => 'Per night (room/unit)',
            self::PerPersonPerNight => 'Per person, per night',
            self::PerDay => 'Per day (vehicle/booking)',
            self::PerPersonPerDay => 'Per person, per day',
            self::PerPerson => 'Per person (one-off)',
            self::PerBooking => 'Per booking (one-off, whole party)',
        };
    }

    /** A party of four pays four times this rate. */
    public function isPerPerson(): bool
    {
        return match ($this) {
            self::PerPersonPerNight, self::PerPersonPerDay, self::PerPerson => true,
            self::PerNight, self::PerDay, self::PerBooking => false,
        };
    }

    /**
     * Whether the rate repeats for every night/day of the stage, as opposed to
     * being charged once no matter how long the trip is. Accommodation is
     * always the former (the plan counts a stay per night it covers); it's
     * vehicles where the distinction bites — a flat package price must not be
     * multiplied by the trip length.
     */
    public function isRecurring(): bool
    {
        return match ($this) {
            self::PerNight, self::PerPersonPerNight, self::PerDay, self::PerPersonPerDay => true,
            self::PerPerson, self::PerBooking => false,
        };
    }

    /**
     * The units a listing of this type can plausibly be priced in — the option
     * list both admin panels offer. Narrower than the full set on purpose: an
     * accommodation quoted "per booking" would silently break the per-night
     * arithmetic the trip plan does with it, and offering the option invites
     * exactly that entry.
     *
     * @return array<int, self>
     */
    public static function forType(?ListingType $type): array
    {
        return match ($type) {
            ListingType::Accommodation => [self::PerNight, self::PerPersonPerNight],
            ListingType::Vehicle => [self::PerDay, self::PerPersonPerDay, self::PerBooking],
            ListingType::Activity => [self::PerPerson, self::PerBooking, self::PerPersonPerDay, self::PerDay],
            ListingType::Restaurant => [self::PerPerson, self::PerBooking],
            null => self::cases(),
        };
    }

    /**
     * The same list as a Filament select's options array.
     *
     * @return array<string, string>
     */
    public static function optionsForType(?ListingType $type): array
    {
        $options = [];

        foreach (self::forType($type) as $unit) {
            $options[$unit->value] = $unit->getLabel();
        }

        return $options;
    }
}

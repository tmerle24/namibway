<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of vehicle a listing actually is.
 *
 * Orthogonal to VehicleCategory, which answers "does someone else drive it"
 * (self-drive rental vs. guided tour). This answers "what am I driving", which
 * is the question a Namibia self-drive traveler actually decides on: the road
 * surface they can take and whether they sleep in the vehicle.
 *
 * It exists because the trip plan's only notion of vehicle type was a binary
 * `vehicle_type` ("car" | "camper") on the trip params, matched against
 * listings by searching the free-text `highlights` array for the literal
 * string "Camper" — which cannot tell a rooftop-tent 4x4 from a motorhome, or
 * a sedan from a 4x4, and silently misses any listing whose highlights word it
 * differently. The binary stays (see isCamper(); saved plans and the interview
 * still speak it), but it is now derived from this rather than being the only
 * thing recorded.
 *
 * A null column means "not recorded", which is where every existing row starts
 * — nothing is backfilled from the highlights heuristic, because a guess
 * stamped into this column would be indistinguishable from a value a partner
 * confirmed. The candidate filter falls back to the old heuristic for such
 * rows, so an unfilled catalog behaves exactly as it did before.
 */
enum VehicleClass: string implements HasLabel
{
    case Sedan = 'sedan';
    case Suv = 'suv';
    case Camper4x4 = 'camper_4x4';
    case Motorhome = 'motorhome';
    case Minibus = 'minibus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sedan => 'Sedan (2WD)',
            self::Suv => 'SUV / 4x4',
            self::Camper4x4 => '4x4 camper (rooftop tent)',
            self::Motorhome => 'Motorhome / campervan',
            self::Minibus => 'Minibus (larger group)',
        };
    }

    /**
     * Whether the traveler sleeps in or on the vehicle — the distinction the
     * old binary `vehicle_type` captured, and the one that still drives
     * accommodation choice (campsites vs. lodges) in the itinerary prompt.
     */
    public function isCamper(): bool
    {
        return match ($this) {
            self::Camper4x4, self::Motorhome => true,
            self::Sedan, self::Suv, self::Minibus => false,
        };
    }

    /** The legacy trip-param value this class implies. */
    public function vehicleType(): string
    {
        return $this->isCamper() ? 'camper' : 'car';
    }

    /**
     * The classes that satisfy a legacy "car"|"camper" trip param, so a plan
     * generated before this enum existed (or from an interview where the
     * traveler never got specific) still narrows the catalog the way it used
     * to, just against a real column.
     *
     * @return array<int, self>
     */
    public static function forVehicleType(string $vehicleType): array
    {
        $camper = $vehicleType === 'camper';

        return array_values(array_filter(
            self::cases(),
            fn (self $class): bool => $class->isCamper() === $camper,
        ));
    }

    /**
     * The same list as a Filament select's options array.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $class) {
            $options[$class->value] = $class->getLabel();
        }

        return $options;
    }
}

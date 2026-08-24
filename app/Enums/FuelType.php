<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which pump, at a supply point that has any.
 *
 * Two cases, because Namibia sells two things a hired vehicle runs on and the
 * difference is the difference between driving on and being towed: most 4x4s
 * and every camper conversion are diesel, most saloon hire cars are petrol,
 * and a rural station that has run dry has usually run dry of one of them.
 *
 * An empty list means "not recorded" and not "neither" — same rule as
 * Attraction's nullable booleans. A station recorded as selling only diesel is
 * a statement somebody checked.
 */
enum FuelType: string implements HasLabel
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Petrol => 'Petrol',
            self::Diesel => 'Diesel',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

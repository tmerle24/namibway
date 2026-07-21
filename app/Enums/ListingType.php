<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ListingType: string implements HasColor, HasLabel
{
    case Accommodation = 'accommodation';
    case Activity = 'activity';
    case Restaurant = 'restaurant';
    case Vehicle = 'vehicle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accommodation => 'Accommodation',
            self::Activity => 'Activity',
            self::Restaurant => 'Restaurant',
            self::Vehicle => 'Vehicle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accommodation => 'info',
            self::Activity => 'success',
            self::Restaurant => 'warning',
            self::Vehicle => 'gray',
        };
    }
}

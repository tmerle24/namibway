<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RouteTripType: string implements HasColor, HasLabel
{
    case Safari = 'safari';
    case Adventure = 'adventure';
    case Culture = 'culture';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Safari => 'Safari',
            self::Adventure => 'Adventure',
            self::Culture => 'Culture',
            self::Mixed => 'Mixed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Safari => 'success',
            self::Adventure => 'warning',
            self::Culture => 'info',
            self::Mixed => 'gray',
        };
    }
}

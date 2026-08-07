<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleCategory: string implements HasColor, HasLabel
{
    case SelfDrive = 'self_drive';
    case GuidedTour = 'guided_tour';

    public function getLabel(): string
    {
        return match ($this) {
            self::SelfDrive => 'Self-Drive',
            self::GuidedTour => 'Guided Tour',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SelfDrive => 'info',
            self::GuidedTour => 'success',
        };
    }
}

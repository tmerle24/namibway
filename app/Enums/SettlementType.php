<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SettlementType: string implements HasColor, HasLabel
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
}

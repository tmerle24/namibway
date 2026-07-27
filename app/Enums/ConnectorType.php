<?php

namespace App\Enums;

enum ConnectorType: string
{
    case ResConnect = 'resconnect';
    case NightsBridge = 'nightsbridge';
    case HopeCloud = 'hopecloud';
    case Manual = 'manual';

    public function label(): string
    {
        return match($this) {
            self::ResConnect => 'ResConnect (ResRequest)',
            self::NightsBridge => 'NightsBridge',
            self::HopeCloud => 'hopeCloud',
            self::Manual => 'Manual (email/Filament)',
        };
    }
}

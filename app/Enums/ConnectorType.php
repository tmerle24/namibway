<?php

namespace App\Enums;

enum ConnectorType: string
{
    case ResConnect = 'resconnect';
    case NightsBridge = 'nightsbridge';
    case HopeCloud = 'hopecloud';
    case Nwr = 'nwr';
    case Native = 'native';
    case Wetu = 'wetu';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ResConnect => 'ResConnect (ResRequest)',
            self::NightsBridge => 'NightsBridge',
            self::HopeCloud => 'hopeCloud',
            self::Nwr => 'NWR — Concierge (manual)',
            self::Native => 'NamibWay Native Booking',
            self::Wetu => 'Wetu (content only)',
            self::Manual => 'Manual (email/Filament)',
        };
    }

    public function isBookingConnector(): bool
    {
        return match ($this) {
            self::ResConnect, self::NightsBridge, self::HopeCloud, self::Nwr, self::Native => true,
            default => false,
        };
    }

    public function isContentConnector(): bool
    {
        return $this === self::Wetu;
    }
}

<?php

namespace App\Enums;

/**
 * Which system a property's bookings run through.
 *
 * The labels are product names, because that is what the list is: a lodge
 * manager recognises "NightsBridge" and picks it. Ours therefore reads
 * **NamibWay Booking** rather than "Native" — native is engineering
 * vocabulary for "written here", and sitting between two real products it
 * looked like a mode rather than a thing you can buy. Same brand, no second
 * name to register; the stored value stays `native`, because renaming a
 * column value to fix a label is how a connector stops resolving.
 */
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
            self::Native => 'NamibWay Booking',
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

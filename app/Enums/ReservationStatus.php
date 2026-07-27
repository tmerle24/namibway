<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case OnRequest = 'on_request';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::OnRequest => 'On Request',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }
}

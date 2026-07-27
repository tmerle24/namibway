<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case Pending = 'pending';
    case NwrPending = 'nwr_pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::NwrPending => 'NWR — Manual check required',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::NwrPending => 'danger',
            self::Confirmed => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    public function requiresManualAction(): bool
    {
        return $this === self::NwrPending;
    }
}

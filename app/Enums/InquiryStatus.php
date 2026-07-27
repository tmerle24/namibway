<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case OnRequest = 'on_request';
    case NwrPending = 'nwr_pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::OnRequest => 'On Request — Awaiting partner',
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
            self::Processing => 'info',
            self::OnRequest => 'warning',
            self::NwrPending => 'danger',
            self::Confirmed => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::OnRequest, self::NwrPending]);
    }

    public function requiresManualAction(): bool
    {
        return $this === self::NwrPending;
    }
}

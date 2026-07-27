<?php

namespace App\Observers;

use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;

class InquiryObserver
{
    /**
     * Automatically set the appropriate initial status when an inquiry is created.
     *
     * NWR listings get nwr_pending so the Filament team badge fires immediately.
     * All other listings default to pending.
     */
    public function creating(Inquiry $inquiry): void
    {
        if ($inquiry->status !== null) {
            return;
        }

        $connectorType = $inquiry->listing?->partner?->connector_type;

        $inquiry->status = $connectorType === ConnectorType::Nwr
            ? InquiryStatus::NwrPending
            : InquiryStatus::Pending;
    }
}

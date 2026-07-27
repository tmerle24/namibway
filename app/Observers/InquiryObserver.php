<?php

namespace App\Observers;

use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Jobs\ProcessInquiry;
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
        if ($inquiry->getAttribute('status') !== null) {
            return;
        }

        $connectorType = $inquiry->listing?->partner?->connector_type;

        $inquiry->status = $connectorType === ConnectorType::Nwr
            ? InquiryStatus::NwrPending
            : InquiryStatus::Pending;
    }

    public function created(Inquiry $inquiry): void
    {
        $connectorType = $inquiry->listing?->partner?->connector_type;

        $automatedTypes = [
            ConnectorType::ResConnect,
            ConnectorType::NightsBridge,
            ConnectorType::HopeCloud,
        ];

        if (in_array($connectorType, $automatedTypes)) {
            ProcessInquiry::dispatch($inquiry);
        }
    }
}

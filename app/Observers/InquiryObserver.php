<?php

namespace App\Observers;

use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Jobs\ProcessInquiry;
use App\Mail\PartnerConfirmationRequest;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Mail;

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
        // An inquiry placed by internal code (hold jobs, promoters, tests)
        // carries a pre-set status; a fresh customer submission does not.
        // Only the latter needs a notification — the others are already handled.
        if ($inquiry->status !== InquiryStatus::Pending) {
            return;
        }

        $connectorType = $inquiry->listing?->partner?->connector_type;

        $automatedTypes = [
            ConnectorType::ResConnect,
            ConnectorType::NightsBridge,
            ConnectorType::HopeCloud,
        ];

        // A connector is asked "are these nights free?" — a question a table
        // booking and a food order do not pose. Those go straight to the
        // partner with the same confirm and decline buttons everything else
        // gets; there is simply no availability call to make first.
        if ($inquiry->kind->becomesAStay() && in_array($connectorType, $automatedTypes)) {
            ProcessInquiry::dispatch($inquiry);

            return;
        }

        if ($connectorType === ConnectorType::Nwr) {
            return;
        }

        // Everyone else gets the mail with the two buttons in it, not just a
        // notification. A partner who does not sell through our booking system
        // used to receive "please reply to the guest yourself" and nothing to
        // press, which left the request with no recorded outcome and the guest
        // with no answer from us. Confirm and decline work off the inquiry
        // rather than off a connector, so there was never a reason to withhold
        // them — see App\Services\Booking\InquiryDecisionService.
        //
        // The listing's own contact address first, then the partner — see
        // Inquiry::sellerEmail(). A shop with no listing falls back to its
        // partner email; a property with no recorded contact reaches its
        // owning partner.
        $recipient = $inquiry->sellerEmail();

        if ($recipient) {
            Mail::to($recipient)->send(new PartnerConfirmationRequest($inquiry));
        }
    }
}

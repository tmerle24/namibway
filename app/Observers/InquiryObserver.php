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
        $connectorType = $inquiry->listing?->partner?->connector_type;

        $automatedTypes = [
            ConnectorType::ResConnect,
            ConnectorType::NightsBridge,
            ConnectorType::HopeCloud,
        ];

        if (in_array($connectorType, $automatedTypes)) {
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
        // The listing's own address is the fallback, because a business whose
        // website is asking for enquiries has to receive them even where we
        // never recorded a partner contact.
        $recipient = $inquiry->listing?->partner?->email ?: $inquiry->listing?->contact_email;

        if ($recipient) {
            Mail::to($recipient)->send(new PartnerConfirmationRequest($inquiry));
        }
    }
}

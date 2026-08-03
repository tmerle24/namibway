<?php

namespace App\Services\Booking;

use App\Connectors\ConnectorFactory;
use App\Enums\InquiryStatus;
use App\Mail\GuestBookingConfirmed;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Shared by the signed-URL email flow (PartnerController) and the logged-in
 * owner's dashboard — same transition, same guest email, same connector
 * notification, just two different ways for a partner to reach it.
 */
class InquiryDecisionService
{
    /** @return bool false if the inquiry wasn't awaiting a decision (already handled elsewhere). */
    public function confirm(Inquiry $inquiry): bool
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return false;
        }

        $inquiry->update(['status' => InquiryStatus::Confirmed]);
        Mail::to($inquiry->email)->send(new GuestBookingConfirmed($inquiry));

        $this->notifyConnector($inquiry, 'confirm');

        Log::info("Inquiry [{$inquiry->id}] confirmed by partner");

        return true;
    }

    /** @return bool false if the inquiry wasn't awaiting a decision (already handled elsewhere). */
    public function decline(Inquiry $inquiry): bool
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return false;
        }

        $inquiry->update(['status' => InquiryStatus::Cancelled]);

        $this->notifyConnector($inquiry, 'cancel');

        Log::info("Inquiry [{$inquiry->id}] declined by partner");

        return true;
    }

    private function notifyConnector(Inquiry $inquiry, string $action): void
    {
        $partner = $inquiry->listing?->partner;

        if (! $partner || ! $inquiry->connector_reference) {
            return;
        }

        try {
            $connector = ConnectorFactory::makeBooking($partner);

            if ($action === 'cancel') {
                $connector->cancelReservation($inquiry->connector_reference);
            }
        } catch (InvalidArgumentException) {
            // No automated connector — nothing to notify
        } catch (Throwable $e) {
            Log::error("InquiryDecisionService: connector notification failed for inquiry [{$inquiry->id}]: {$e->getMessage()}");
        }
    }
}

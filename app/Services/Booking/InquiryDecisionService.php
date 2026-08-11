<?php

namespace App\Services\Booking;

use App\Connectors\ConnectorFactory;
use App\Enums\InquiryStatus;
use App\Enums\StayStatus;
use App\Mail\GuestBookingConfirmed;
use App\Models\Inquiry;
use App\Models\Reservation;
use App\Services\Inventory\InventoryWriter;
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
    public function __construct(private readonly StayPromoter $promoter) {}

    /** @return bool false if the inquiry wasn't awaiting a decision (already handled elsewhere). */
    public function confirm(Inquiry $inquiry): bool
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return false;
        }

        $inquiry->update(['status' => InquiryStatus::Confirmed]);
        Mail::to($inquiry->email)->send(new GuestBookingConfirmed($inquiry));

        // The request is now a stay: it holds real inventory, it appears on the
        // arrivals board, and the property can work from it. Quietly, because a
        // calendar that cannot take it is the team's problem to fix and never a
        // reason to undo a confirmation the guest has already been sent.
        $this->promoter->promoteQuietly($inquiry->refresh());

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

        // The room this request was holding goes back on sale. A partner who
        // declines has said no; leaving the hold behind would keep the room
        // off the market until it expired on its own.
        $this->releaseHold($inquiry);

        $this->notifyConnector($inquiry, 'cancel');

        Log::info("Inquiry [{$inquiry->id}] declined by partner");

        return true;
    }

    /**
     * Cancel the provisional stay a request was holding. Guarded on the status
     * so a stay that has already moved on is left alone, and silent when there
     * was no hold — most requests, for now.
     */
    private function releaseHold(Inquiry $inquiry): void
    {
        $held = Reservation::query()
            ->where('inquiry_id', $inquiry->id)
            ->where('status', StayStatus::Provisional)
            ->first();

        if ($held !== null) {
            app(InventoryWriter::class)->cancel($held, 'Request declined by the property');
        }
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

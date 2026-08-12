<?php

namespace App\Services\Booking;

use App\Connectors\ConnectorFactory;
use App\Enums\InquiryStatus;
use App\Enums\StayStatus;
use App\Mail\GuestBookingConfirmed;
use App\Models\Inquiry;
use App\Models\Reservation;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\PaymentGateway;
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
        return $this->settle($inquiry)->handled;
    }

    /**
     * Confirm and ask for the deposit in the same movement.
     *
     * One click for the property and **one email for the guest**, not two: the
     * confirmation carries the payment button and whatever the partner wanted
     * to say alongside it. A separate "and now here is a link" mail arriving
     * seconds later reads as a system talking to itself.
     */
    public function confirmAndRequestDeposit(Inquiry $inquiry, ?string $message = null): ConfirmationOutcome
    {
        return $this->settle($inquiry, askForDeposit: true, message: $message);
    }

    private function settle(Inquiry $inquiry, bool $askForDeposit = false, ?string $message = null): ConfirmationOutcome
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return ConfirmationOutcome::alreadyHandled();
        }

        $inquiry->update(['status' => InquiryStatus::Confirmed]);

        // The request is now a stay: it holds real inventory, it appears on the
        // arrivals board, and the property can work from it. Quietly, because a
        // calendar that cannot take it is the team's problem to fix and never a
        // reason to undo a confirmation the guest is about to be sent.
        //
        // This runs before the guest's email rather than after it — which is
        // the other way round from how it was first written — because a payment
        // link can only be attached to a stay that exists.
        $stay = $this->promoter->promoteQuietly($inquiry->refresh());

        [$paymentUrl, $problem] = $askForDeposit ? $this->depositLink($stay) : [null, null];

        // The partner's own words go out whether or not the link could be made:
        // they wrote them to the guest, not to the payment provider.
        Mail::to($inquiry->email)->send(new GuestBookingConfirmed($inquiry, $paymentUrl, $message));

        $this->notifyConnector($inquiry, 'confirm');

        Log::info("Inquiry [{$inquiry->id}] confirmed by partner");

        return ConfirmationOutcome::confirmed($paymentUrl, $problem);
    }

    /**
     * The link to ask this stay's guest for their deposit, or the reason there
     * is none — in the words the person who pressed the button should read.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function depositLink(?Reservation $stay): array
    {
        if ($stay === null) {
            return [null, 'The booking is confirmed and the guest has been told, but it could not be '
                .'put on the calendar as a stay — so there was nothing to attach a payment to. '
                .'We have been alerted and will sort it out.'];
        }

        try {
            return [app(PaymentGateway::class)->linkFor($stay), null];
        } catch (InvalidArgumentException $refusal) {
            return [null, 'The booking is confirmed and the guest has been told, but no payment link '
                .'went with it: '.lcfirst($refusal->getMessage())];
        }
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

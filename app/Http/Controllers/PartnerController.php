<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorFactory;
use App\Enums\InquiryStatus;
use App\Mail\GuestBookingConfirmed;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class PartnerController extends Controller
{
    public function confirm(Inquiry $inquiry): Response|RedirectResponse
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return Inertia::render('partner/ActionResult', [
                'outcome' => 'already_handled',
                'message' => 'This booking has already been processed.',
            ]);
        }

        $inquiry->update(['status' => InquiryStatus::Confirmed]);
        Mail::to($inquiry->email)->send(new GuestBookingConfirmed($inquiry));

        $this->notifyConnector($inquiry, 'confirm');

        Log::info("Partner confirmed inquiry [{$inquiry->id}] via signed URL");

        return Inertia::render('partner/ActionResult', [
            'outcome' => 'confirmed',
            'message' => 'Booking confirmed. The guest has been notified.',
            'inquiry' => [
                'id' => $inquiry->id,
                'guest_name' => $inquiry->name,
                'listing_name' => $inquiry->listing->name,
                'check_in' => $inquiry->check_in?->toDateString(),
                'check_out' => $inquiry->check_out?->toDateString(),
                'connector_reference' => $inquiry->connector_reference,
            ],
        ]);
    }

    public function cancel(Inquiry $inquiry): Response|RedirectResponse
    {
        if ($inquiry->status !== InquiryStatus::OnRequest) {
            return Inertia::render('partner/ActionResult', [
                'outcome' => 'already_handled',
                'message' => 'This booking has already been processed.',
            ]);
        }

        $inquiry->update(['status' => InquiryStatus::Cancelled]);

        $this->notifyConnector($inquiry, 'cancel');

        Log::info("Partner cancelled inquiry [{$inquiry->id}] via signed URL");

        return Inertia::render('partner/ActionResult', [
            'outcome' => 'cancelled',
            'message' => 'Booking declined. The request has been cancelled.',
            'inquiry' => [
                'id' => $inquiry->id,
                'guest_name' => $inquiry->name,
                'listing_name' => $inquiry->listing->name,
            ],
        ]);
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
            Log::error("PartnerController: connector notification failed for inquiry [{$inquiry->id}]: {$e->getMessage()}");
        }
    }
}

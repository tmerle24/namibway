<?php

namespace App\Jobs;

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Mail\GuestBookingConfirmed;
use App\Mail\PartnerConfirmationRequest;
use App\Models\Inquiry;
use App\Services\Booking\StayPromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class ProcessInquiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(private readonly Inquiry $inquiry) {}

    public function handle(): void
    {
        $inquiry = $this->inquiry->fresh(['listing.partner']);
        $partner = $inquiry?->listing?->partner;

        if (! $partner) {
            return;
        }

        try {
            $connector = ConnectorFactory::makeBooking($partner);
        } catch (InvalidArgumentException) {
            return;
        }

        $propertyCode = $inquiry->listing->connector_property_code ?? '';
        $inquiry->update(['status' => InquiryStatus::Processing]);

        $availResponse = $connector->checkAvailability(new AvailabilityRequest(
            propertyCode: $propertyCode,
            checkIn: $inquiry->check_in ?? now()->addDays(30),
            checkOut: $inquiry->check_out ?? now()->addDays(33),
            adults: $inquiry->adults,
            children: $inquiry->children,
        ));

        if (! $availResponse->available) {
            $inquiry->update([
                'status' => InquiryStatus::Failed,
                'notes' => 'No availability: '.($availResponse->error ?? 'property full'),
            ]);
            Log::info("ProcessInquiry [{$inquiry->id}]: no availability for {$propertyCode}");

            return;
        }

        // The connector's own vocabulary, not ours: every PMS in this market
        // says "room type", and AvailabilityResponse is the mapping layer.
        $bookableUnits = $availResponse->roomTypes;
        $bookableUnit = $inquiry->bookable_unit_code
            ? collect($bookableUnits)->firstWhere('code', $inquiry->bookable_unit_code)
            : ($bookableUnits[0] ?? null);

        if (! $bookableUnit) {
            $inquiry->update(['status' => InquiryStatus::Failed, 'notes' => 'No matching room type']);

            return;
        }

        $resResponse = $connector->createReservation(new ReservationRequest(
            propertyCode: $propertyCode,
            // The connector DTO keeps the partner's word; the value is ours.
            roomTypeCode: $bookableUnit->code,
            checkIn: $inquiry->check_in ?? now()->addDays(30),
            checkOut: $inquiry->check_out ?? now()->addDays(33),
            adults: $inquiry->adults,
            children: $inquiry->children,
            guestName: $inquiry->name,
            guestEmail: $inquiry->email,
            guestPhone: $inquiry->phone ?? '',
            specialRequests: $inquiry->message,
            inquiryId: $inquiry->id,
        ));

        if (! $resResponse->success) {
            $inquiry->update([
                'status' => InquiryStatus::Failed,
                'notes' => 'Reservation failed: '.($resResponse->error ?? 'unknown error'),
            ]);
            Log::error("ProcessInquiry [{$inquiry->id}]: reservation failed — {$resResponse->error}");

            return;
        }

        $inquiry->update([
            'connector_reference' => $resResponse->connectorReference,
            'bookable_unit_code' => $bookableUnit->code,
            'total_amount' => $resResponse->totalAmount,
            'currency' => $resResponse->currency ?? 'NAD',
        ]);

        if ($resResponse->status === ReservationStatus::Confirmed) {
            $inquiry->update(['status' => InquiryStatus::Confirmed]);
            Mail::to($inquiry->email)->send(new GuestBookingConfirmed($inquiry));

            // Confirmed without a partner ever deciding, so the stay is written
            // here rather than in InquiryDecisionService. Same rule: a calendar
            // that cannot take it is an alert, never a cancellation.
            app(StayPromoter::class)->promoteQuietly($inquiry->refresh());
        } elseif ($resResponse->status === ReservationStatus::OnRequest) {
            $inquiry->update(['status' => InquiryStatus::OnRequest]);

            if ($partner->email) {
                Mail::to($partner->email)->send(new PartnerConfirmationRequest($inquiry));
            }
        }
    }
}

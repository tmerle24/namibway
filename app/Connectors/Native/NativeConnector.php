<?php

namespace App\Connectors\Native;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Connectors\ResConnect\DTOs\RoomType as RoomTypeDTO;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Jobs\ExpireNativeHoldJob;
use App\Models\BookableUnit;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Services\Booking\RoomAvailability;
use App\Services\Booking\StayPromoter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * NamibWay's own booking engine for partners with no PMS — no HTTP client,
 * pure Eloquent against BookableUnit/Inquiry. Availability is derived (total_units
 * minus overlapping active Inquiry rows), not stored in a calendar table.
 * See app/Models/BookableUnit.php for why a unit's identity is a plain `code`
 * string rather than a foreign key on Inquiry.
 */
class NativeConnector implements BookingConnector
{
    private const HOLD_HOURS = 24;

    public function getConnectorType(): string
    {
        return 'native';
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse
    {
        $listing = Listing::where('slug', $request->propertyCode)->first();

        if (! $listing) {
            return AvailabilityResponse::unavailable($request->propertyCode, 'Listing not found');
        }

        $nights = max(1, $request->checkIn->diffInDays($request->checkOut));

        $roomTypes = $listing->bookableUnits()
            ->where('is_active', true)
            ->get()
            ->map(function (BookableUnit $bookableUnit) use ($request, $listing, $nights) {
                $available = $this->availableUnits($listing->id, $bookableUnit, $request->checkIn, $request->checkOut);

                if ($available < 1) {
                    return null;
                }

                return new RoomTypeDTO(
                    code: $bookableUnit->code,
                    name: $bookableUnit->name,
                    available: $available,
                    ratePerNight: (float) $bookableUnit->base_rate,
                    currency: $bookableUnit->currency,
                    totalRate: (float) $bookableUnit->base_rate * $nights,
                    description: $bookableUnit->description,
                );
            })
            ->filter()
            ->values()
            ->all();

        return new AvailabilityResponse(
            propertyCode: $request->propertyCode,
            available: count($roomTypes) > 0,
            roomTypes: $roomTypes,
        );
    }

    public function createReservation(ReservationRequest $request): ReservationResponse
    {
        $listing = Listing::where('slug', $request->propertyCode)->first();

        if (! $listing) {
            return ReservationResponse::failed('Listing not found');
        }

        return DB::transaction(function () use ($request, $listing) {
            $bookableUnit = BookableUnit::where('listing_id', $listing->id)
                ->where('code', $request->roomTypeCode)
                ->lockForUpdate()
                ->first();

            if (! $bookableUnit) {
                return ReservationResponse::failed('Room type not found');
            }

            // Re-checks availability under a row lock: ProcessInquiry calls
            // checkAvailability() and createReservation() as two separate,
            // non-atomic steps, so a concurrent request could have consumed
            // the last unit in between.
            $available = $this->availableUnits($listing->id, $bookableUnit, $request->checkIn, $request->checkOut);

            if ($available < 1) {
                return ReservationResponse::failed('Room type sold out for these dates');
            }

            $nights = max(1, $request->checkIn->diffInDays($request->checkOut));
            $totalAmount = (float) $bookableUnit->base_rate * $nights;

            if ($request->inquiryId) {
                Inquiry::whereKey($request->inquiryId)->update([
                    'hold_expires_at' => now()->addHours(self::HOLD_HOURS),
                ]);

                ExpireNativeHoldJob::dispatch($request->inquiryId)->delay(now()->addHours(self::HOLD_HOURS));

                // The hold, on the calendar, as a provisional stay. Until this
                // existed the "hold" was a timestamp and nothing else: the room
                // stayed on sale in the panel and the lodge could sell it out
                // from under the traveller while the request sat waiting.
                $inquiry = Inquiry::find($request->inquiryId);

                if ($inquiry !== null) {
                    app(StayPromoter::class)->hold($inquiry);
                }
            }

            return new ReservationResponse(
                success: true,
                status: ReservationStatus::OnRequest,
                connectorReference: "NATIVE-{$request->inquiryId}",
                totalAmount: $totalAmount,
                currency: $bookableUnit->currency,
            );
        });
    }

    public function getReservation(string $reference): ReservationResponse
    {
        $inquiryId = (int) str_replace('NATIVE-', '', $reference);
        $inquiry = Inquiry::find($inquiryId);

        if (! $inquiry) {
            return ReservationResponse::failed('Reservation not found');
        }

        $status = match ($inquiry->status) {
            InquiryStatus::Confirmed => ReservationStatus::Confirmed,
            InquiryStatus::Cancelled, InquiryStatus::Failed => ReservationStatus::Cancelled,
            default => ReservationStatus::OnRequest,
        };

        return new ReservationResponse(
            success: true,
            status: $status,
            connectorReference: $reference,
            totalAmount: $inquiry->total_amount !== null ? (float) $inquiry->total_amount : null,
            currency: $inquiry->currency,
        );
    }

    /**
     * No external system to notify — inventory frees automatically since
     * Cancelled inquiries are excluded from availableUnits()'s overlap count.
     */
    public function cancelReservation(string $reference): bool
    {
        return true;
    }

    /**
     * Thin pass-through to RoomAvailability, which the trip plan's room picker
     * also asks — one definition of "free", not two that can drift apart.
     */
    private function availableUnits(
        int $listingId,
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
    ): int {
        return RoomAvailability::unitsLeft($listingId, $bookableUnit, $checkIn, $checkOut);
    }
}

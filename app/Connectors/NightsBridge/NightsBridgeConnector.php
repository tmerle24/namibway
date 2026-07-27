<?php

namespace App\Connectors\NightsBridge;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Connectors\ResConnect\DTOs\RoomType;
use App\Enums\ReservationStatus;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class NightsBridgeConnector implements BookingConnector
{
    public function __construct(
        private readonly NightsBridgeClient $client,
    ) {}

    public function getConnectorType(): string
    {
        return 'nightsbridge';
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse
    {
        try {
            $data = $this->client->get('availability', [
                'BBId' => $request->propertyCode,
                'ArrivalDate' => $request->checkIn->format('Y-m-d'),
                'DepartureDate' => $request->checkOut->format('Y-m-d'),
                'NumberOfAdults' => $request->adults,
                'NumberOfChildren' => $request->children,
            ]);

            $roomTypes = collect($data['Rooms'] ?? $data['rooms'] ?? [])
                ->map(fn (array $r) => new RoomType(
                    code: (string) ($r['RoomId'] ?? $r['room_id'] ?? ''),
                    name: $r['RoomName'] ?? $r['room_name'] ?? 'Unknown',
                    available: (int) ($r['Availability'] ?? $r['availability'] ?? 0),
                    ratePerNight: (float) ($r['Rate'] ?? $r['rate'] ?? 0),
                    currency: $r['Currency'] ?? $r['currency'] ?? 'NAD',
                    totalRate: (float) ($r['TotalRate'] ?? $r['total_rate'] ?? 0),
                    mealPlan: $r['MealPlan'] ?? $r['meal_plan'] ?? null,
                    description: $r['Description'] ?? $r['description'] ?? null,
                ))
                ->filter(fn (RoomType $rt) => $rt->available > 0)
                ->values()
                ->all();

            return new AvailabilityResponse(
                propertyCode: $request->propertyCode,
                available: count($roomTypes) > 0,
                roomTypes: $roomTypes,
            );

        } catch (RequestException $e) {
            Log::error('NightsBridge availability check failed', [
                'property' => $request->propertyCode,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return AvailabilityResponse::unavailable(
                $request->propertyCode,
                'NightsBridge API error: '.$e->response->status(),
            );
        }
    }

    public function createReservation(ReservationRequest $request): ReservationResponse
    {
        try {
            $data = $this->client->post('reservations', [
                'BBId' => $request->propertyCode,
                'RoomId' => $request->roomTypeCode,
                'ArrivalDate' => $request->checkIn->format('Y-m-d'),
                'DepartureDate' => $request->checkOut->format('Y-m-d'),
                'NumberOfAdults' => $request->adults,
                'NumberOfChildren' => $request->children,
                'GuestName' => $request->guestName,
                'GuestEmail' => $request->guestEmail,
                'GuestPhone' => $request->guestPhone,
                'SpecialRequests' => $request->specialRequests,
                'Source' => 'namibway',
                'SourceReference' => (string) $request->inquiryId,
            ]);

            $status = match (strtolower($data['Status'] ?? $data['status'] ?? '')) {
                'confirmed' => ReservationStatus::Confirmed,
                'on_request', 'onrequest', 'pending' => ReservationStatus::OnRequest,
                default => ReservationStatus::Pending,
            };

            return new ReservationResponse(
                success: true,
                status: $status,
                connectorReference: (string) ($data['BookingId'] ?? $data['booking_id'] ?? ''),
                confirmationNumber: $data['ConfirmationNumber'] ?? $data['confirmation_number'] ?? null,
                totalAmount: isset($data['TotalAmount']) ? (float) $data['TotalAmount'] : (isset($data['total_amount']) ? (float) $data['total_amount'] : null),
                currency: $data['Currency'] ?? $data['currency'] ?? 'NAD',
            );

        } catch (RequestException $e) {
            Log::error('NightsBridge reservation creation failed', [
                'property' => $request->propertyCode,
                'guest' => $request->guestEmail,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return ReservationResponse::failed(
                'NightsBridge API error: '.$e->response->status(),
            );
        }
    }

    public function getReservation(string $reference): ReservationResponse
    {
        try {
            $data = $this->client->get("reservations/{$reference}");

            $status = match (strtolower($data['Status'] ?? $data['status'] ?? '')) {
                'confirmed' => ReservationStatus::Confirmed,
                'on_request', 'onrequest' => ReservationStatus::OnRequest,
                'cancelled', 'canceled' => ReservationStatus::Cancelled,
                default => ReservationStatus::Pending,
            };

            return new ReservationResponse(
                success: true,
                status: $status,
                connectorReference: $reference,
                confirmationNumber: $data['ConfirmationNumber'] ?? $data['confirmation_number'] ?? null,
                totalAmount: isset($data['TotalAmount']) ? (float) $data['TotalAmount'] : null,
                currency: $data['Currency'] ?? 'NAD',
            );

        } catch (RequestException $e) {
            Log::error('NightsBridge get reservation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return ReservationResponse::failed('NightsBridge API error: '.$e->response->status());
        }
    }

    public function cancelReservation(string $reference): bool
    {
        try {
            $this->client->post("reservations/{$reference}/cancel");

            return true;

        } catch (RequestException $e) {
            Log::error('NightsBridge cancellation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return false;
        }
    }
}

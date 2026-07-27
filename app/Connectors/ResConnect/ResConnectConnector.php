<?php

namespace App\Connectors\ResConnect;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Connectors\ResConnect\DTOs\RoomType;
use App\Enums\ReservationStatus;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class ResConnectConnector implements BookingConnector
{
    public function __construct(
        private readonly ResConnectClient $client,
    ) {}

    public function getConnectorType(): string
    {
        return 'resconnect';
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse
    {
        try {
            $data = $this->client->get('availability', [
                'property_code' => $request->propertyCode,
                'check_in' => $request->checkIn->toDateString(),
                'check_out' => $request->checkOut->toDateString(),
                'adults' => $request->adults,
                'children' => $request->children,
            ]);

            /** @var array<int, array<string, mixed>> $rawRoomTypes */
            $rawRoomTypes = $data['room_types'] ?? [];
            $roomTypes = collect($rawRoomTypes)
                ->map(fn (array $rt) => new RoomType(
                    code: $rt['code'],
                    name: $rt['name'],
                    available: (int) ($rt['available'] ?? 0),
                    ratePerNight: (float) ($rt['rate_per_night'] ?? 0),
                    currency: $rt['currency'] ?? 'NAD',
                    totalRate: (float) ($rt['total_rate'] ?? 0),
                    mealPlan: $rt['meal_plan'] ?? null,
                    description: $rt['description'] ?? null,
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
            Log::error('ResConnect availability check failed', [
                'property' => $request->propertyCode,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return AvailabilityResponse::unavailable(
                $request->propertyCode,
                'ResConnect API error: '.$e->response->status(),
            );
        }
    }

    public function createReservation(ReservationRequest $request): ReservationResponse
    {
        try {
            $data = $this->client->post('reservations', [
                'property_code' => $request->propertyCode,
                'room_type_code' => $request->roomTypeCode,
                'check_in' => $request->checkIn->toDateString(),
                'check_out' => $request->checkOut->toDateString(),
                'adults' => $request->adults,
                'children' => $request->children,
                'guest' => [
                    'name' => $request->guestName,
                    'email' => $request->guestEmail,
                    'phone' => $request->guestPhone,
                ],
                'special_requests' => $request->specialRequests,
                'source' => 'namibway',
                'source_reference' => $request->inquiryId,
            ]);

            $status = match ($data['status'] ?? '') {
                'confirmed' => ReservationStatus::Confirmed,
                'on_request' => ReservationStatus::OnRequest,
                default => ReservationStatus::Pending,
            };

            return new ReservationResponse(
                success: true,
                status: $status,
                connectorReference: $data['reservation_id'] ?? null,
                confirmationNumber: $data['confirmation_number'] ?? null,
                totalAmount: isset($data['total_amount']) ? (float) $data['total_amount'] : null,
                currency: $data['currency'] ?? 'NAD',
            );

        } catch (RequestException $e) {
            Log::error('ResConnect reservation creation failed', [
                'property' => $request->propertyCode,
                'guest' => $request->guestEmail,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return ReservationResponse::failed(
                'ResConnect API error: '.$e->response->status(),
            );
        }
    }

    public function getReservation(string $reference): ReservationResponse
    {
        try {
            $data = $this->client->get("reservations/{$reference}");

            $status = match ($data['status'] ?? '') {
                'confirmed' => ReservationStatus::Confirmed,
                'on_request' => ReservationStatus::OnRequest,
                'cancelled' => ReservationStatus::Cancelled,
                default => ReservationStatus::Pending,
            };

            return new ReservationResponse(
                success: true,
                status: $status,
                connectorReference: $reference,
                confirmationNumber: $data['confirmation_number'] ?? null,
                totalAmount: isset($data['total_amount']) ? (float) $data['total_amount'] : null,
                currency: $data['currency'] ?? 'NAD',
            );

        } catch (RequestException $e) {
            Log::error('ResConnect get reservation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return ReservationResponse::failed('ResConnect API error: '.$e->response->status());
        }
    }

    public function cancelReservation(string $reference): bool
    {
        try {
            $this->client->post("reservations/{$reference}/cancel");

            return true;

        } catch (RequestException $e) {
            Log::error('ResConnect cancellation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return false;
        }
    }
}

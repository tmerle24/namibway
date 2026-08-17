<?php

namespace App\Connectors\HopeCloud;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\HopeCloud\DTOs\HopeCloudReservationResponse;
use App\Connectors\HopeCloud\DTOs\TourismLevyInfo;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Connectors\ResConnect\DTOs\RoomType;
use App\Enums\ReservationStatus;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class HopeCloudConnector implements BookingConnector
{
    public function __construct(
        private readonly HopeCloudClient $client,
    ) {}

    public function getConnectorType(): string
    {
        return 'hopecloud';
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse
    {
        try {
            $data = $this->client->get('availability', [
                'property_id' => $request->propertyCode,
                'check_in' => $request->checkIn->toDateString(),
                'check_out' => $request->checkOut->toDateString(),
                'adults' => $request->adults,
                'children' => $request->children,
            ]);

            $nights = $request->checkIn->diffInDays($request->checkOut);
            $persons = $request->adults + $request->children;

            $levyRate = (float) ($data['tourism_levy_rate'] ?? 0);

            /** @var array<int, array<string, mixed>> $rawRoomTypes */
            $rawRoomTypes = $data['room_types'] ?? [];
            $roomTypes = collect($rawRoomTypes)
                ->map(function (array $rt) use ($levyRate, $nights, $persons): RoomType {
                    $baseRate = (float) ($rt['base_rate'] ?? 0);
                    $levyTotal = $levyRate * $persons * $nights;

                    return new RoomType(
                        code: $rt['code'],
                        name: $rt['name'],
                        available: (int) ($rt['available'] ?? 0),
                        ratePerNight: $baseRate,
                        currency: $rt['currency'] ?? 'NAD',
                        totalRate: (float) ($rt['total_rate'] ?? ($baseRate * $nights + $levyTotal)),
                        mealPlan: $rt['meal_plan'] ?? null,
                        description: isset($rt['description'])
                            ? $rt['description'].' [Tourism Levy: N$'.number_format($levyTotal, 2).' incl.]'
                            : null,
                    );
                })
                ->filter(fn (RoomType $rt) => $rt->available > 0)
                ->values()
                ->all();

            return new AvailabilityResponse(
                propertyCode: $request->propertyCode,
                available: count($roomTypes) > 0,
                roomTypes: $roomTypes,
            );

        } catch (RequestException $e) {
            Log::error('hopeCloud availability check failed', [
                'property' => $request->propertyCode,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return AvailabilityResponse::unavailable(
                $request->propertyCode,
                'hopeCloud API error: '.$e->response->status(),
            );
        }
    }

    public function createReservation(ReservationRequest $request): ReservationResponse
    {
        try {
            $data = $this->client->post('reservations', [
                'property_id' => $request->propertyCode,
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
                'report_tourism_levy' => true,
            ]);

            $status = match ($data['status'] ?? '') {
                'confirmed' => ReservationStatus::Confirmed,
                'on_request' => ReservationStatus::OnRequest,
                default => ReservationStatus::Pending,
            };

            $levy = isset($data['tourism_levy'])
                ? new TourismLevyInfo(
                    ratePerPersonPerNight: (float) ($data['tourism_levy']['rate_per_person_per_night'] ?? 0),
                    currency: $data['tourism_levy']['currency'] ?? 'NAD',
                    totalLevy: (float) ($data['tourism_levy']['total'] ?? 0),
                    nights: (int) ($data['tourism_levy']['nights'] ?? 0),
                    persons: (int) ($data['tourism_levy']['persons'] ?? 0),
                    includedInRate: (bool) ($data['tourism_levy']['included_in_rate'] ?? false),
                )
                : null;

            return new HopeCloudReservationResponse(
                success: true,
                status: $status,
                connectorReference: $data['reservation_id'] ?? null,
                confirmationNumber: $data['confirmation_number'] ?? null,
                totalAmount: isset($data['total_amount']) ? (float) $data['total_amount'] : null,
                currency: $data['currency'] ?? 'NAD',
                tourismLevy: $levy,
                ntbReportReference: $data['ntb_report_reference'] ?? null,
            );

        } catch (RequestException $e) {
            Log::error('hopeCloud reservation creation failed', [
                'property' => $request->propertyCode,
                'guest' => $request->guestEmail,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return HopeCloudReservationResponse::failed(
                'hopeCloud API error: '.$e->response->status(),
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

            return new HopeCloudReservationResponse(
                success: true,
                status: $status,
                connectorReference: $reference,
                confirmationNumber: $data['confirmation_number'] ?? null,
                totalAmount: isset($data['total_amount']) ? (float) $data['total_amount'] : null,
                currency: $data['currency'] ?? 'NAD',
                ntbReportReference: $data['ntb_report_reference'] ?? null,
            );

        } catch (RequestException $e) {
            Log::error('hopeCloud get reservation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return HopeCloudReservationResponse::failed('hopeCloud API error: '.$e->response->status());
        }
    }

    public function cancelReservation(string $reference): bool
    {
        try {
            $this->client->post("reservations/{$reference}/cancel");

            return true;

        } catch (RequestException $e) {
            Log::error('hopeCloud cancellation failed', [
                'reference' => $reference,
                'status' => $e->response->status(),
            ]);

            return false;
        }
    }
}

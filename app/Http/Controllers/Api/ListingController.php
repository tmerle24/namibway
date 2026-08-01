<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use RuntimeException;

class ListingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $listings = Listing::query()
            ->where('is_published', true)
            ->filterBy($request->query())
            ->orderByDesc('is_featured')
            ->orderByDesc('rating')
            ->paginate(max($perPage, 1))
            ->withQueryString();

        return ListingResource::collection($listings);
    }

    public function show(Listing $listing): ListingResource
    {
        abort_unless($listing->is_published, 404);

        return ListingResource::make($listing);
    }

    /**
     * The middleware call: resolves the listing's partner and either proxies
     * to their live connector (ResConnect/NightsBridge/hopeCloud/NWR-concierge)
     * or, for Manual/Wetu/unconfigured partners, reports that only the inquiry
     * flow is available. See ConnectorType::isBookingConnector().
     */
    public function availability(Request $request, Listing $listing): JsonResponse
    {
        abort_unless($listing->is_published, 404);

        $validated = $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
        ]);

        $partner = $listing->partner;
        $connectorType = $partner?->connector_type;

        if (! $partner || ! $connectorType?->isBookingConnector() || blank($listing->connector_property_code)) {
            return response()->json([
                'live_availability' => false,
                'booking_mode' => 'inquiry',
            ]);
        }

        try {
            $availability = ConnectorFactory::makeBooking($partner)->checkAvailability(new AvailabilityRequest(
                propertyCode: $listing->connector_property_code,
                checkIn: CarbonImmutable::parse($validated['check_in']),
                checkOut: CarbonImmutable::parse($validated['check_out']),
                adults: (int) ($validated['adults'] ?? 2),
                children: (int) ($validated['children'] ?? 0),
            ));
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return response()->json([
                'live_availability' => false,
                'booking_mode' => 'connector',
                'error' => 'Upstream connector is currently unavailable.',
            ], 502);
        }

        return response()->json([
            'live_availability' => true,
            'booking_mode' => 'connector',
            'connector' => $connectorType->value,
            'available' => $availability->available,
            'room_types' => collect($availability->roomTypes)->map(fn ($roomType) => [
                'code' => $roomType->code,
                'name' => $roomType->name,
                'available' => $roomType->available,
                'rate_per_night' => $roomType->ratePerNight,
                'currency' => $roomType->currency,
                'total_rate' => $roomType->totalRate,
                'meal_plan' => $roomType->mealPlan,
                'description' => $roomType->description,
            ])->values(),
            'error' => $availability->error,
        ]);
    }
}

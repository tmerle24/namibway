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

/**
 * @group Listings
 *
 * Query NamibWay's published listings and check live availability.
 */
class ListingController extends Controller
{
    /**
     * List listings
     *
     * Published listings only, paginated and filterable. Filters mirror the
     * internal Explore search (see Listing::scopeFilterBy()).
     *
     * @queryParam type string Filter by listing type. Example: accommodation
     * @queryParam vehicle_category string Filter vehicle listings by category: `self_drive` or `guided_tour`. Example: self_drive
     * @queryParam vehicle_class string Filter vehicle listings by what they are: `sedan`, `suv`, `camper_4x4`, `motorhome` or `minibus`. Example: camper_4x4
     * @queryParam region string Filter by region (partial match). Example: Erongo
     * @queryParam keyword string Free-text search across name/description/region/type. Example: dune
     * @queryParam budget string One of `budget`, `mid-range`, `premium` (price_from bands). Example: mid-range
     * @queryParam min_rating number Minimum rating, 0-5. Example: 4
     * @queryParam per_page integer Results per page, max 100. Example: 20
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 10, "type": "accommodation", "name": "Desert Lodge", "slug": "desert-lodge",
     *       "description": "A quiet lodge on the edge of the dunes.", "short_description": null,
     *       "image": "https://namibway.com/storage/listings/desert-lodge.jpg", "gallery": [],
     *       "region": "Hardap", "latitude": -24.7592, "longitude": 15.7092,
     *       "price_from": 1500.0, "price_currency": "NAD", "price_unit": "per_night",
     *       "rating": 4.5, "rating_count": 12, "accepts_inquiries": true,
     *       "booking_mode": "connector"
     *     }
     *   ],
     *   "links": {"first": "https://namibway.com/api/v1/listings?page=1", "last": "https://namibway.com/api/v1/listings?page=1", "prev": null, "next": null},
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 1}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $listings = Listing::query()
            ->where('is_published', true)
            ->with('city.region')
            ->filterBy($request->query())
            ->orderByDesc('is_featured')
            ->orderByDesc('rating')
            ->paginate(max($perPage, 1))
            ->withQueryString();

        return ListingResource::collection($listings);
    }

    /**
     * Get a listing
     *
     * @urlParam listing string required The listing's slug. Example: desert-lodge
     *
     * @response 200 {
     *   "data": {
     *     "id": 10, "type": "accommodation", "name": "Desert Lodge", "slug": "desert-lodge",
     *     "description": "A quiet lodge on the edge of the dunes.", "short_description": null,
     *     "image": "https://namibway.com/storage/listings/desert-lodge.jpg", "gallery": [],
     *     "region": "Hardap", "latitude": -24.7592, "longitude": 15.7092,
     *     "price_from": 1500.0, "price_currency": "NAD", "price_unit": "per_night",
     *     "rating": 4.5, "rating_count": 12, "accepts_inquiries": true,
     *     "booking_mode": "connector"
     *   }
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\Listing]."}
     */
    public function show(Listing $listing): ListingResource
    {
        abort_unless($listing->is_published, 404);

        return ListingResource::make($listing);
    }

    /**
     * Check availability
     *
     * The middleware call: resolves the listing's partner and either proxies
     * to their live connector (ResConnect/NightsBridge/hopeCloud/NamibWay's own
     * booking system/NWR-concierge) or, for Manual/Wetu/unconfigured partners,
     * reports that only the inquiry flow is available. See
     * ConnectorType::isBookingConnector().
     *
     * @urlParam listing string required The listing's slug. Example: desert-lodge
     *
     * @queryParam check_in string required Check-in date (YYYY-MM-DD), today or later. Example: 2026-09-01
     * @queryParam check_out string required Check-out date (YYYY-MM-DD), after check_in. Example: 2026-09-05
     * @queryParam adults integer Number of adults. Defaults to 2. Example: 2
     * @queryParam children integer Number of children. Defaults to 0. Example: 0
     *
     * @response 200 scenario="connector-backed listing, rooms available" {
     *   "live_availability": true, "booking_mode": "connector", "connector": "resconnect",
     *   "available": true,
     *   "room_types": [
     *     {"code": "std", "name": "Standard Room", "available": 2, "base_rate": 1500.0, "currency": "NAD", "total_rate": 6000.0, "meal_plan": null, "description": null}
     *   ],
     *   "error": null
     * }
     * @response 200 scenario="no connector configured — inquiry only" {
     *   "live_availability": false, "booking_mode": "inquiry"
     * }
     * @response 502 scenario="upstream connector unavailable" {
     *   "live_availability": false, "booking_mode": "connector", "error": "Upstream connector is currently unavailable."
     * }
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
                'base_rate' => $roomType->ratePerNight,
                'currency' => $roomType->currency,
                'total_rate' => $roomType->totalRate,
                'meal_plan' => $roomType->mealPlan,
                'description' => $roomType->description,
            ])->values(),
            'error' => $availability->error,
        ]);
    }
}

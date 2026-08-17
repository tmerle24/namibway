<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\Booking\SlotOffer;
use App\Services\Booking\UnitAvailability;
use App\Services\Booking\UnitOffer;
use App\Services\Pricing\ComputedCharge;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /availability — what a given listing has to offer for a set of travel
 * parameters, one endpoint for every vertical.
 *
 * The caller — Kaia, the listing page, a customer website — must not need to
 * know which vertical it is serving. See BOOKING_BEYOND_ROOMS.md §7.1 for the
 * decision and its stated deviation from OpenTravel's per-domain shape.
 *
 * No `check_out` is required: a tour has no departure date, so its absence
 * signals "slot-based" and the service uses the check_in date. `time` filters
 * departures from a given hour onwards, never specifies one — the response is
 * what is offered; the traveller picks.
 */
class AvailabilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'listing'   => ['required', 'string', 'exists:listings,slug'],
            'check_in'  => ['required', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'time'      => ['nullable', 'date_format:H:i'],
            'adults'    => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children'  => ['sometimes', 'integer', 'min:0', 'max:20'],
        ]);

        $listing = Listing::where('slug', $validated['listing'])->firstOrFail();

        abort_unless($listing->is_published, 404);

        $checkIn  = CarbonImmutable::parse($validated['check_in']);
        $checkOut = isset($validated['check_out'])
            ? CarbonImmutable::parse($validated['check_out'])
            : $checkIn->addDay();

        $adults   = (int) ($validated['adults'] ?? 1);
        $children = (int) ($validated['children'] ?? 0);
        $timeFrom = $validated['time'] ?? null;

        $periods = max(1, $checkIn->diffInDays($checkOut));

        $offers = app(UnitAvailability::class)->for(
            $listing,
            $checkIn,
            $checkOut,
            $adults,
            $children,
            $timeFrom,
        );

        return response()->json([
            'periods' => $periods,
            'units'   => collect($offers)->map(fn (UnitOffer $offer) => [
                'code'         => $offer->bookableUnit->code,
                'name'         => $offer->bookableUnit->name,
                'description'  => $offer->bookableUnit->description,
                'max_adults'   => $offer->bookableUnit->max_adults,
                'max_children' => $offer->bookableUnit->max_children,
                'slots'        => collect($offer->slots)->map(fn (SlotOffer $slot) => [
                    'starts_at'        => $slot->startsAt,
                    'label'            => $slot->label,
                    'duration_minutes' => $slot->durationMinutes,
                    'units_left'       => $slot->unitsLeft,
                    'total'            => $slot->total,
                    'total_payable'    => $slot->totalPayable,
                ])->values(),
                'total'        => $offer->total,
                'total_payable' => $offer->totalPayable,
                'charges'      => collect($offer->charges)->map(fn (ComputedCharge $charge) => [
                    'name'     => $charge->name,
                    'amount'   => $charge->amount,
                    'included' => $charge->isIncluded,
                ])->values(),
                'currency'    => $offer->currency,
                'units_left'  => $offer->unitsLeft,
                'gallery'     => collect($offer->bookableUnit->gallery ?? [])
                    ->map(fn (string $path) => self::resolveMediaUrl($path))
                    ->values(),
            ])->values(),
        ]);
    }
}

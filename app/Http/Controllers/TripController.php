<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\BookableUnit;
use App\Models\Inquiry;
use App\Models\ItineraryItem;
use App\Models\Listing;
use App\Models\SavedPlan;
use App\Models\Trip;
use App\Services\Booking\ActiveRequestGate;
use App\Services\Booking\RoomCapacity;
use App\Services\Kaia\ItineraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TripController extends Controller
{
    /**
     * Turn a trip plan into booking requests.
     *
     * Two rules from CLAUDE.md's account line are enforced here, and neither
     * had an enforcement point before: booking needs an account (the route is
     * behind `auth`), and **only the plan's creator may book it**. Sharing a
     * plan for co-planning does not hand over the ability to send requests —
     * that's what keeps "one active request pipeline per traveler" coherent,
     * since it assumes exactly one responsible person per pipeline.
     *
     * "Creator" is resolved from the plan token the booking is made against:
     * - the token must be the plan's *edit* token; a read-only share token
     *   resolves to a real plan but its holder is a co-planner, not the owner,
     * - a plan that already belongs to an account may only be booked by that
     *   account,
     * - a plan with no owner yet (the anonymous token-only path, which is most
     *   of them) is claimed by the booker: possession of the edit token is
     *   what "creator" means until someone attaches an identity to it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:20',
            'children' => 'nullable|integer|min:0|max:20',
            'variant_name' => 'required|string|max:255',
            'plan' => 'required|array',
            'variant_days' => 'required|array',
            // Required: without it there is no way to tell the creator from
            // someone who was shown the plan. The frontend always has one by
            // this point — the plan autosaves as soon as it is generated — and
            // mints one first if a save happened to have failed.
            'plan_token' => 'required|string|max:64',
        ]);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $plan = SavedPlan::where('token', $validated['plan_token'])->first();

        // Covers both "no such plan" and "that's the read-only share token".
        // Deliberately the same answer for both, so a share link can't be used
        // to probe for the existence of its editable twin.
        if ($plan === null) {
            return response()->json([
                'error' => __('Only the traveler who created this plan can send booking requests for it.'),
            ], 403);
        }

        if ($plan->user_id !== null && $plan->user_id !== $user->id) {
            return response()->json([
                'error' => __('Only the traveler who created this plan can send booking requests for it.'),
            ], 403);
        }

        if (ActiveRequestGate::blocks($user->id, $validated['email'])) {
            return response()->json(['error' => ActiveRequestGate::message()], 422);
        }

        /** @var list<array<string, mixed>> $variantDays */
        $variantDays = $validated['variant_days'];

        // A guided trip is one product: the operator drives it, and the nights
        // and activities in the plan are what he quotes on. So it is one
        // request to him rather than one to every lodge — which is the
        // flooding problem this whole mechanic exists to prevent, solving
        // itself. See TRAVEL_PLAN.md, "travelling guided".
        $tour = $this->guidedTour($validated['plan'], $variantDays);

        // One inquiry per accommodation, carrying the room the traveler picked
        // for it. Without `bookable_unit_code` the choice was decorative — the
        // connector received a request with no room on it and every option
        // ended up the same booking. First pick wins for a multi-night stay:
        // the picker sets the same selection across the run, and a plan edited
        // into disagreeing with itself shouldn't silently reserve the later
        // room.
        /** @var array<int, string|null> $roomByListing */
        $roomByListing = [];

        foreach ($variantDays as $day) {
            $listingId = isset($day['accommodation']['id']) ? (int) $day['accommodation']['id'] : null;

            if ($listingId === null) {
                continue;
            }

            if (! array_key_exists($listingId, $roomByListing)) {
                $code = $day['room_selection']['code'] ?? null;
                $roomByListing[$listingId] = is_string($code) && $code !== '' ? $code : null;
            }
        }

        // Capacity is a rule on this path and not a filter. The picker only
        // ever declined to *offer* a room that does not fit, which a party
        // growing after the room was chosen walks straight past — and nobody
        // is standing here to judge whether the room can take a fourth child.
        // At a desk that judgement exists and the answer is different; see
        // BOOKING_SYSTEM.md and App\Services\Booking\RoomCapacity.
        $tooSmall = $this->roomsTooSmall(
            $roomByListing,
            (int) $validated['adults'],
            (int) ($validated['children'] ?? 0),
        );

        if ($tooSmall !== []) {
            return response()->json([
                'error' => __('One of the rooms you picked is too small for your party.'),
                'rooms' => $tooSmall,
            ], 422);
        }

        // Booking is the strongest possible commitment signal, so an anonymous
        // plan becomes this account's plan at the same moment — otherwise the
        // trip they just booked wouldn't show up on their own dashboard. After
        // the gate *and* after the capacity check, so a rejected request
        // leaves no trace on the plan and no trip nobody asked for.
        if ($plan->user_id === null) {
            $plan->update(['user_id' => $user->id]);
        }

        $trip = Trip::create([
            'user_id' => $user->id,
            'saved_plan_id' => $plan->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $validated['children'] ?? 0,
            'variant_name' => $validated['variant_name'],
            'plan' => $validated['plan'],
        ]);

        // Collect first and last date in the plan for each accommodation, so the
        // ItineraryItem records the exact leg rather than the whole trip span.
        // Dates in variant_days are stored as 'j M Y' strings (e.g. "3 Aug 2026").
        /** @var array<int, array{first: Carbon, last: Carbon}> $datesByListing */
        $datesByListing = [];

        foreach ($variantDays as $day) {
            $dayListingId = isset($day['accommodation']['id']) ? (int) $day['accommodation']['id'] : null;

            if ($dayListingId === null || ! array_key_exists($dayListingId, $roomByListing)) {
                continue;
            }

            $dayDateStr = $day['date'] ?? null;

            if (! is_string($dayDateStr)) {
                continue;
            }

            try {
                $dayDate = Carbon::createFromFormat('j M Y', $dayDateStr)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if (! isset($datesByListing[$dayListingId])) {
                $datesByListing[$dayListingId] = ['first' => $dayDate, 'last' => $dayDate];
            } else {
                if ($dayDate->lt($datesByListing[$dayListingId]['first'])) {
                    $datesByListing[$dayListingId]['first'] = $dayDate;
                }
                if ($dayDate->gt($datesByListing[$dayListingId]['last'])) {
                    $datesByListing[$dayListingId]['last'] = $dayDate;
                }
            }
        }

        $sort = 0;

        if ($tour !== null) {
            $item = ItineraryItem::create([
                'saved_plan_id' => $plan->id,
                'listing_id' => $tour->id,
                'kind' => $tour->type,
                'date' => $validated['check_in'],
                'date_to' => $validated['check_out'],
                'sort' => 0,
            ]);

            $inquiry = Inquiry::create([
                'listing_id' => $tour->id,
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'adults' => $validated['adults'],
                'children' => $validated['children'] ?? 0,
                // The plan is the brief: the operator prices this route, and
                // without it in the request he would have to ask for it.
                'message' => $this->guidedBrief($validated['variant_name'], $variantDays),
                'status' => InquiryStatus::Pending,
            ]);

            $item->update(['inquiry_id' => $inquiry->id]);

            return response()->json([
                'trip_id' => $trip->id,
                'inquiry_count' => 1,
            ]);
        }

        foreach ($roomByListing as $listingId => $bookableUnitCode) {
            $checkIn = $datesByListing[$listingId]['first'] ?? null;
            // check-out is the morning after the last night — the day the guest
            // leaves, not the last night they sleep there.
            $checkOut = isset($datesByListing[$listingId]['last'])
                ? $datesByListing[$listingId]['last']->copy()->addDay()
                : null;

            $item = ItineraryItem::create([
                'saved_plan_id' => $plan->id,
                'listing_id' => $listingId,
                'kind' => ListingType::Accommodation,
                'date' => $checkIn,
                'date_to' => $checkOut,
                'sort' => $sort++,
            ]);

            $inquiry = Inquiry::create([
                'listing_id' => $listingId,
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'adults' => $validated['adults'],
                'children' => $validated['children'] ?? 0,
                'bookable_unit_code' => $bookableUnitCode,
                'status' => InquiryStatus::Pending,
            ]);

            $item->update(['inquiry_id' => $inquiry->id]);
        }

        return response()->json([
            'trip_id' => $trip->id,
            'inquiry_count' => count($roomByListing),
        ]);
    }

    /**
     * The guided tour this plan is built around, or null when the traveller is
     * driving themselves.
     *
     * Both halves have to agree: the plan says the traveller chose to be
     * driven, and the variant names a listing that really is a guided tour. A
     * plan carrying one without the other is not a guided trip — it is a plan
     * somebody edited, and the lodges are then booked the ordinary way rather
     * than quietly sent to an operator who was never chosen.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $variantDays
     */
    private function guidedTour(array $plan, array $variantDays): ?Listing
    {
        if (($plan['trip_params']['vehicle_type'] ?? null) !== ItineraryService::GUIDED) {
            return null;
        }

        $id = $this->guidedVehicleId($plan, $variantDays);

        if ($id === null) {
            return null;
        }

        $listing = Listing::find($id);

        return $listing?->vehicle_category === VehicleCategory::GuidedTour ? $listing : null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $variantDays
     */
    private function guidedVehicleId(array $plan, array $variantDays): ?int
    {
        foreach ((array) ($plan['variants'] ?? []) as $variant) {
            $id = $variant['vehicle']['id'] ?? null;

            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        // A variant sent on its own, without the plan around it.
        foreach ($variantDays as $day) {
            $id = $day['vehicle']['id'] ?? null;

            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * The route as the operator needs to read it: one line per day, with where
     * the traveller sleeps and what they wanted to do there.
     *
     * @param  list<array<string, mixed>>  $variantDays
     */
    private function guidedBrief(string $variantName, array $variantDays): string
    {
        $lines = ['Guided trip requested from the NamibWay plan "'.$variantName.'".', ''];

        foreach ($variantDays as $day) {
            $parts = array_filter([
                'Day '.($day['day'] ?? '?'),
                is_string($day['date'] ?? null) ? $day['date'] : null,
                is_string($day['location'] ?? null) ? $day['location'] : null,
            ]);

            $stay = $day['accommodation']['name'] ?? null;
            $activities = array_filter(array_map(
                fn ($entry) => is_array($entry) ? ($entry['name'] ?? null) : null,
                (array) ($day['activities'] ?? []),
            ));

            $line = implode(' · ', $parts);

            if (is_string($stay) && $stay !== '') {
                $line .= ' — stay: '.$stay;
            }

            if ($activities !== []) {
                $line .= ' — '.implode(', ', $activities);
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'The lodges and activities above are the traveller\'s plan, not a booking — quote what you would do.';

        return implode("\n", $lines);
    }

    /**
     * The rooms on this booking that the party does not fit into, said in words
     * a traveller can act on.
     *
     * A room chosen for a plan is named by code, so a code that names nothing —
     * a room type retired since the plan was made — is left alone rather than
     * refused: the request goes to the property without a room on it, which is
     * what a plan with no picker does anyway, and is a question the partner can
     * answer.
     *
     * @param  array<int, string|null>  $roomByListing  listing id => room type code
     * @return array<int, string>
     */
    private function roomsTooSmall(array $roomByListing, int $adults, int $children): array
    {
        $problems = [];

        foreach ($roomByListing as $listingId => $code) {
            if ($code === null) {
                continue;
            }

            $bookableUnit = BookableUnit::query()
                ->where('listing_id', $listingId)
                ->where('code', $code)
                ->first();

            if (! $bookableUnit instanceof BookableUnit || RoomCapacity::fits($bookableUnit, $adults, $children)) {
                continue;
            }

            $problems[] = RoomCapacity::explain([[$bookableUnit, 1]], $adults, $children);
        }

        return $problems;
    }

    /**
     * Live status of a trip's requests, polled by BookingSection right after
     * booking. Scoped to the trip's owner: the id is a plain auto-increment, so
     * without the check this listed any traveler's booked lodges and their
     * confirmation states to anyone who counted upwards. Trips created before
     * bookings had an owner have no `user_id` and are no longer reachable here
     * — they predate the account requirement and nothing is polling them.
     */
    public function inquiries(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($trip->user_id !== null && $trip->user_id === $request->user()?->id, 403);

        $inquiries = $trip->inquiries()
            ->with('listing:id,name')
            ->get(['id', 'trip_id', 'listing_id', 'status']);

        $data = $inquiries->map(fn (Inquiry $inquiry) => [
            'listing_name' => $inquiry->sellerName(),
            'status' => $inquiry->status->value,
            'label' => $inquiry->status->label(),
        ]);

        return response()->json(['inquiries' => $data]);
    }
}

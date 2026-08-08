<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Destination;
use App\Models\Listing;
use App\Models\Region;
use App\Models\SavedPlan;
use App\Services\Kaia\InterviewService;
use App\Services\Kaia\ItineraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class KaiaController extends Controller
{
    public function regions(): JsonResponse
    {
        $regions = Region::query()
            ->whereHas('cities.listings', fn ($q) => $q->where('is_published', true))
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['regions' => $regions]);
    }

    public function cities(Request $request): JsonResponse
    {
        $query = City::query()->orderBy('name');

        // Day-location editing (LocationPicker) needs this filtered to
        // cities Kaia can actually book something in. Startort/Zielort are
        // pure routing endpoints — the traveler may start/end anywhere
        // (e.g. Windhoek, which itself often has no published lodge
        // listings) — so that caller passes ?all=1 to skip the filter.
        if (! $request->boolean('all')) {
            $query->whereHas('listings', fn ($q) => $q->where('is_published', true));
        }

        return response()->json(['cities' => $query->pluck('name')]);
    }

    public function regionCoords(): JsonResponse
    {
        $destinations = Destination::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('sort_order')
            ->with('region:id,name')
            ->get(['id', 'name', 'lat', 'lng', 'image', 'region_id']);

        $coords = $destinations->mapWithKeys(fn (Destination $d) => [
            mb_strtolower($d->getTranslation('name', 'en')) => [
                'lat' => $d->lat,
                'lng' => $d->lng,
                'image' => $d->image ? self::resolveMediaUrl($d->image) : null,
            ],
        ]);

        // Also key by the political region itself — some older saved plans still
        // carry a region-valued "location" (from before day-locations switched to
        // city granularity), so this keeps their trip map/day thumbnails working.
        // First destination row per political region (by sort_order above) wins;
        // never overrides a destination-name key since no seeded destination
        // shares its name with its own political region.
        foreach ($destinations->groupBy(fn (Destination $d) => $d->region->name) as $politicalRegion => $group) {
            $key = mb_strtolower((string) $politicalRegion);

            if ($coords->has($key)) {
                continue;
            }

            /** @var Destination $first */
            $first = $group->first();
            $coords[$key] = [
                'lat' => $first->lat,
                'lng' => $first->lng,
                'image' => $first->image ? self::resolveMediaUrl($first->image) : null,
            ];
        }

        // Also key by City — a day's "location" is now always a city (see
        // ItineraryService), so this is what actually resolves the trip map's
        // per-day marker/thumbnail today. Only cities with a published listing
        // are included, mirroring cities()/regions() above. Never overrides a
        // destination-name key (destinations have curated images; a bare city
        // row has none).
        City::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereHas('listings', fn ($q) => $q->where('is_published', true))
            ->get(['id', 'name', 'lat', 'lng'])
            ->each(function (City $city) use ($coords) {
                $key = mb_strtolower($city->name);

                if (! $coords->has($key)) {
                    $coords[$key] = ['lat' => $city->lat, 'lng' => $city->lng, 'image' => null];
                }
            });

        return response()->json(['coords' => $coords]);
    }

    public function savePlan(Request $request): JsonResponse
    {
        $request->validate(['variant' => 'required|array']);

        $planData = $request->input('variant');
        $title = Str::limit($planData['trip_summary'] ?? '', 80, '');

        $saved = SavedPlan::create([
            'title' => $title,
            'plan_json' => $planData,
            'user_id' => $request->user()?->id,
            'session_id' => $request->session()->getId(),
        ]);

        return response()->json([
            'token' => $saved->token,
            'url' => route('trip.show', $saved->token),
            // The link to hand to other people: same plan, no write access.
            'share_token' => $saved->share_token,
            'share_url' => route('trip.show', $saved->share_token),
            'version' => $saved->version,
        ]);
    }

    public function loadPlan(string $token): JsonResponse
    {
        $resolved = SavedPlan::resolveByAnyToken($token);

        abort_if($resolved === null, 404);

        [$plan, $canEdit] = $resolved;

        // `version` is what the client must hand back on its next update for
        // the conflict check in updatePlan() to be able to spot a stale write.
        // `can_edit` is false for a read-only share link — the UI hides its
        // editing affordances, but updatePlan() below is the actual gate.
        return response()->json([
            'variant' => $plan->plan_json,
            'version' => $plan->version,
            'can_edit' => $canEdit,
            // Safe to hand to either audience: it's the reader's own token for
            // a read-only visitor, and the link an editor would want to share.
            'share_token' => $plan->share_token,
        ]);
    }

    // Re-saves an existing token's plan in place (edits made after the
    // itinerary was first generated — swapped items, dismissed variants,
    // reordered days) rather than minting a new token for every change.
    //
    // Optimistic concurrency: the whole plan_json document is overwritten on
    // every autosave, so without a guard two editors of the same token (the
    // share link is the same token, and one person can just have two tabs
    // open) silently overwrite each other, last write wins. A client that
    // sends the `version` it loaded gets a 409 — carrying the current server
    // state so it can resolve — instead of clobbering. The check and the write
    // are one atomic conditional UPDATE, so two simultaneous requests can't
    // both pass it.
    //
    // `version` is optional so a client that predates it keeps working
    // unchanged (it just gets the old last-write-wins behaviour); it still
    // bumps the counter, so a versioned client editing alongside it is
    // protected.
    public function updatePlan(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'variant' => 'required|array',
            'version' => 'sometimes|integer|min:1',
        ]);

        // Deliberately only the edit token: a read-only share link resolves to
        // a real plan but must not be able to write to it. 404 rather than 403
        // so a share link doesn't advertise that an editable twin exists.
        $saved = SavedPlan::where('token', $token)->firstOrFail();

        $planData = $validated['variant'];
        $title = Str::limit($planData['trip_summary'] ?? '', 80, '');
        $expected = $validated['version'] ?? null;

        if ($expected === null) {
            $saved->update(['title' => $title, 'plan_json' => $planData]);
            $saved->increment('version');

            return response()->json(['token' => $saved->token, 'version' => $saved->version]);
        }

        // json_encode by hand: an Eloquent *builder* update bypasses the
        // model's `array` cast (unlike $model->update()), so plan_json would
        // otherwise be handed to the driver as a PHP array.
        $written = SavedPlan::query()
            ->whereKey($saved->id)
            ->where('version', $expected)
            ->update([
                'title' => $title,
                'plan_json' => json_encode($planData),
                'version' => $expected + 1,
            ]);

        if ($written === 0) {
            return response()->json([
                'message' => 'This plan was changed somewhere else since you opened it.',
                'version' => $saved->fresh()?->version,
                'variant' => $saved->fresh()?->plan_json,
            ], 409);
        }

        return response()->json(['token' => $saved->token, 'version' => $expected + 1]);
    }

    // Re-runs itinerary generation from scratch with traveler-edited trip
    // parameters (dates, party, preferences, budget, start/end city) — used
    // by the "edit trip details" popup on an already-generated plan, as
    // opposed to message()'s conversational interview flow.
    public function regenerate(Request $request, ItineraryService $itinerary): JsonResponse
    {
        $validated = $request->validate([
            'nights' => ['required', 'integer', 'min:1', 'max:60'],
            'travel_period' => ['required', 'string', 'max:120'],
            'interests' => ['nullable', 'string', 'max:500'],
            'budget_tier' => ['required', 'string', 'in:budget,mid-range,premium'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children_under_13' => ['required', 'integer', 'min:0', 'max:20'],
            'children_ages' => ['nullable', 'string', 'max:200'],
            'vehicle_type' => ['required', 'string', 'in:car,camper'],
            'start_location' => ['nullable', 'string', 'max:120'],
            'end_location' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $plan = $itinerary->generate($validated);

            return response()->json(['plan' => $plan]);
        } catch (RuntimeException $e) {
            Log::error('Kaia itinerary regeneration failed: '.$e->getMessage());

            return response()->json([
                'error' => 'Could not update the plan. Please try again.',
            ], 502);
        }
    }

    public function message(Request $request, InterviewService $interview, ItineraryService $itinerary): JsonResponse
    {
        $validated = $request->validate([
            'history' => ['required', 'array', 'min:1', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:ai,user'],
            'history.*.text' => ['required', 'string', 'max:1000'],
        ]);

        $messages = array_map(fn (array $turn) => [
            'role' => $turn['role'] === 'ai' ? 'assistant' : 'user',
            'content' => $turn['text'],
        ], $validated['history']);

        try {
            $result = $interview->respond($messages, app()->getLocale());

            if ($result['type'] === 'question') {
                return response()->json(['type' => 'question', 'text' => $result['text']]);
            }

            if ($result['type'] === 'search_intent') {
                return response()->json(['type' => 'search_intent', 'intent' => $result['intent']]);
            }

            if ($result['type'] === 'recommend_intent') {
                $intent = $result['intent'];
                $query = Listing::query()->where('is_published', true);

                if (isset($intent['type']) && is_string($intent['type'])) {
                    $query->where('type', $intent['type']);
                }

                if (isset($intent['region']) && is_string($intent['region'])) {
                    $region = $intent['region'];
                    $query->whereHas('city', fn ($q) => $q->where('name', 'ilike', '%'.$region.'%')
                        ->orWhereHas('region', fn ($q2) => $q2->where('name', 'ilike', '%'.$region.'%')));
                }

                $listing = $query->orderByDesc('is_featured')->orderByDesc('rating')->first();

                $listingData = $listing ? [
                    'id' => $listing->id,
                    'type' => $listing->type->value,
                    'name' => $listing->name,
                    'slug' => $listing->slug,
                    'region' => $listing->region,
                    'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
                    'price_from' => $listing->price_from,
                    'price_currency' => $listing->price_currency,
                    'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                    'rating_count' => $listing->rating_count,
                ] : null;

                return response()->json([
                    'type' => 'recommendation',
                    'intro' => $intent['intro'] ?? '',
                    'listing' => $listingData,
                ]);
            }

            $plan = $itinerary->generate($result['params']);

            return response()->json(['type' => 'itinerary', 'plan' => $plan]);
        } catch (RuntimeException $e) {
            Log::error('Kaia request failed: '.$e->getMessage());

            return response()->json([
                'type' => 'error',
                'text' => 'Something went wrong reaching the travel companion. Please try again.',
            ], 502);
        }
    }
}

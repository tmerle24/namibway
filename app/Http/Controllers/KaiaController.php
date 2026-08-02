<?php

namespace App\Http\Controllers;

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
        $regions = Listing::query()
            ->where('is_published', true)
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');

        return response()->json(['regions' => $regions]);
    }

    public function regionCoords(): JsonResponse
    {
        $regions = Region::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('sort_order')
            ->get(['name', 'lat', 'lng', 'image', 'listing_region']);

        $coords = $regions->mapWithKeys(fn (Region $r) => [
            mb_strtolower($r->getTranslation('name', 'en')) => [
                'lat' => $r->lat,
                'lng' => $r->lng,
                'image' => $r->image ? self::resolveMediaUrl($r->image) : null,
            ],
        ]);

        // Also key by the political region itself (Listing::region / a day's
        // "location") — a day's location is always one of the 6 political
        // regions, never a destination name, so it otherwise never gets an
        // image for the itinerary day thumbnail fallback. First region row
        // per political region (by sort_order above) wins; never overrides a
        // destination-name key since no seeded destination shares its name
        // with its own political region.
        foreach ($regions->groupBy('listing_region') as $politicalRegion => $group) {
            $key = mb_strtolower((string) $politicalRegion);

            if ($coords->has($key)) {
                continue;
            }

            /** @var Region $first */
            $first = $group->first();
            $coords[$key] = [
                'lat' => $first->lat,
                'lng' => $first->lng,
                'image' => $first->image ? self::resolveMediaUrl($first->image) : null,
            ];
        }

        return response()->json(['coords' => $coords]);
    }

    public function alternatives(Request $request, ItineraryService $itinerary): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:accommodation,activity,restaurant,vehicle'],
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $excludeId = isset($validated['exclude_id']) ? (int) $validated['exclude_id'] : null;
        $results = $itinerary->alternatives($validated['type'], $excludeId);

        return response()->json(['alternatives' => $results]);
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
        ]);
    }

    public function loadPlan(string $token): JsonResponse
    {
        $plan = SavedPlan::where('token', $token)->firstOrFail();

        return response()->json(['variant' => $plan->plan_json]);
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
                    $query->where('region', 'ilike', '%'.$intent['region'].'%');
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

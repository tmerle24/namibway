<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Region;
use App\Models\TripPlan;
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
        $coords = Region::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['name', 'lat', 'lng'])
            ->mapWithKeys(fn (Region $r) => [
                mb_strtolower($r->getTranslation('name', 'en')) => ['lat' => $r->lat, 'lng' => $r->lng],
            ]);

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

        $token = Str::random(8);
        TripPlan::create([
            'token' => $token,
            'plan_data' => $request->input('variant'),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json(['token' => $token]);
    }

    public function loadPlan(string $token): JsonResponse
    {
        $plan = TripPlan::where('token', $token)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();

        return response()->json(['variant' => $plan->plan_data]);
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

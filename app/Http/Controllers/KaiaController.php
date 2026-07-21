<?php

namespace App\Http\Controllers;

use App\Services\Kaia\InterviewService;
use App\Services\Kaia\ItineraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KaiaController extends Controller
{
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
            $result = $interview->respond($messages);

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

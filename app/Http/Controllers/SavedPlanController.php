<?php

namespace App\Http\Controllers;

use App\Models\SavedPlan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SavedPlanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'array'],
            'plan.trip_summary' => ['required', 'string', 'max:500'],
            'plan.variants' => ['required', 'array', 'min:1'],
        ]);

        $plan = $validated['plan'];
        $title = Str::limit($plan['trip_summary'], 80, '');

        $saved = SavedPlan::create([
            'title' => $title,
            'plan_json' => $plan,
            'user_id' => $request->user()?->id,
            'session_id' => $request->session()->getId(),
        ]);

        return response()->json([
            'token' => $saved->token,
            'url' => route('trip.show', $saved->token),
        ]);
    }

    public function show(string $token): Response
    {
        $saved = SavedPlan::where('token', $token)->firstOrFail();

        return Inertia::render('TripPlan', [
            'plan' => $saved->plan_json,
            'title' => $saved->title,
            'token' => $saved->token,
            'shareUrl' => route('trip.show', $token),
        ]);
    }

    public function pdf(Request $request, string $token): HttpResponse
    {
        $saved = SavedPlan::where('token', $token)->firstOrFail();

        $pdf = Pdf::loadView('pdf.trip-plan', [
            'plan' => $saved->plan_json,
            'title' => $saved->title,
        ]);

        $filename = Str::slug($saved->title ?: 'namibway-trip') . '.pdf';

        return $pdf->download($filename);
    }
}

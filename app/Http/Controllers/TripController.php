<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
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
        ]);

        $activeStatuses = collect(InquiryStatus::cases())
            ->filter(fn (InquiryStatus $s) => $s->isActive())
            ->map(fn (InquiryStatus $s) => $s->value)
            ->all();

        if (Inquiry::where('email', $validated['email'])->whereIn('status', $activeStatuses)->exists()) {
            return response()->json([
                'error' => 'You already have a pending booking request. Please wait for it to be resolved before submitting a new one.',
            ], 422);
        }

        $trip = Trip::create([
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

        /** @var list<array<string, mixed>> $variantDays */
        $variantDays = $validated['variant_days'];

        /** @var array<int, int> $accommodationIds */
        $accommodationIds = collect($variantDays)
            ->map(fn (array $day) => isset($day['accommodation']['id']) ? (int) $day['accommodation']['id'] : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($accommodationIds as $listingId) {
            Inquiry::create([
                'listing_id' => $listingId,
                'trip_id' => $trip->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'adults' => $validated['adults'],
                'children' => $validated['children'] ?? 0,
                'status' => InquiryStatus::Pending,
            ]);
        }

        return response()->json([
            'trip_id' => $trip->id,
            'inquiry_count' => count($accommodationIds),
        ]);
    }

    public function inquiries(Trip $trip): JsonResponse
    {
        $inquiries = $trip->inquiries()
            ->with('listing:id,name')
            ->get(['id', 'trip_id', 'listing_id', 'status']);

        $data = $inquiries->map(fn (Inquiry $inquiry) => [
            'listing_name' => $inquiry->listing->name,
            'status' => $inquiry->status->value,
            'label' => $inquiry->status->label(),
        ]);

        return response()->json(['inquiries' => $data]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Mail\NewInquiryReceived;
use App\Models\Inquiry;
use App\Models\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function show(Listing $listing): Response
    {
        abort_unless($listing->is_published, 404);

        $listing->load('partner');

        return Inertia::render('ListingDetail', [
            'listing' => [
                'id' => $listing->id,
                'type' => $listing->type->value,
                'name' => $listing->name,
                'slug' => $listing->slug,
                'description' => $listing->description,
                'highlights' => $listing->highlights ?? [],
                'image' => $listing->image ? Storage::disk('public')->url($listing->image) : null,
                'gallery' => collect($listing->gallery ?? [])
                    ->map(fn (string $path) => Storage::disk('public')->url($path))
                    ->values(),
                'region' => $listing->region,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'accepts_inquiries' => $listing->accepts_inquiries,
                'partner' => $listing->partner ? [
                    'name' => $listing->partner->name,
                    'logo' => $listing->partner->logo ? Storage::disk('public')->url($listing->partner->logo) : null,
                    'website' => $listing->partner->website,
                    'instagram' => $listing->partner->instagram,
                    'facebook' => $listing->partner->facebook,
                ] : null,
            ],
        ]);
    }

    public function storeInquiry(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($listing->is_published && $listing->accepts_inquiries, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'travel_dates' => ['nullable', 'string', 'max:255'],
            'check_in' => ['nullable', 'date', 'after_or_equal:today'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $activeStatuses = array_map(
            fn (InquiryStatus $s) => $s->value,
            array_filter(InquiryStatus::cases(), fn (InquiryStatus $s) => $s->isActive())
        );

        $alreadyActive = Inquiry::where('email', $validated['email'])
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($alreadyActive) {
            return back()->withErrors([
                'email' => __('You already have an active booking request in progress. Please wait for it to be resolved before submitting a new one.'),
            ]);
        }

        $inquiry = $listing->inquiries()->create($validated);

        if ($listing->partner?->email) {
            Mail::to($listing->partner->email)->send(new NewInquiryReceived($inquiry));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inquiry sent.')]);

        return back();
    }
}

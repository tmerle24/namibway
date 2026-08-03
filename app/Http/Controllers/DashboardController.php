<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\SavedPlan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $isOwner = $user?->partner_id !== null;

        $plans = SavedPlan::query()
            ->where('user_id', $user?->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'token', 'title', 'created_at']);

        $listings = collect();
        $bookings = collect();

        if ($isOwner) {
            $listings = Listing::query()
                ->where('partner_id', $user->partner_id)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'slug', 'name', 'is_published', 'updated_at'])
                ->map(fn (Listing $listing) => [
                    'id' => $listing->id,
                    'slug' => $listing->slug,
                    'name' => $listing->getTranslation('name', 'en', useFallbackLocale: false),
                    'is_published' => $listing->is_published,
                    'updated_at' => $listing->updated_at,
                ])
                ->values();

            $bookings = Inquiry::query()
                ->whereHas('listing', fn ($query) => $query->where('partner_id', $user->partner_id))
                ->with('listing:id,slug,name')
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->map(fn (Inquiry $inquiry) => [
                    'id' => $inquiry->id,
                    'listing_name' => $inquiry->listing?->getTranslation('name', 'en', useFallbackLocale: false),
                    'listing_slug' => $inquiry->listing?->slug,
                    'guest_name' => $inquiry->name,
                    'status' => $inquiry->status->value,
                    'check_in' => $inquiry->check_in?->toDateString(),
                    'check_out' => $inquiry->check_out?->toDateString(),
                    'travel_dates' => $inquiry->travel_dates,
                    'created_at' => $inquiry->created_at,
                ])
                ->values();
        }

        $defaultTab = $isOwner ? 'listings' : 'trips';
        $requestedTab = $request->query('tab');
        $tab = in_array($requestedTab, ['trips', 'listings', 'bookings'], true)
            ? $requestedTab
            : $defaultTab;

        return Inertia::render('Dashboard', [
            'isOwner' => $isOwner,
            'activeTab' => $tab,
            'plans' => $plans,
            'listings' => $listings,
            'bookings' => $bookings,
        ]);
    }
}

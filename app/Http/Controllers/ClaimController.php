<?php

namespace App\Http\Controllers;

use App\Mail\PartnerDeclinedListing;
use App\Models\Partner;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ClaimController extends Controller
{
    public function show(string $token): Response
    {
        $partner = Partner::where('claim_token', $token)->firstOrFail();

        $listing = $partner->listings()->first();

        return Inertia::render('Claim', [
            'partner' => [
                'name' => $partner->name,
                'website' => $partner->website,
                'claimed_at' => $partner->claimed_at?->toDateString(),
                'rejected_at' => $partner->claim_rejected_at?->toDateString(),
            ],
            'listing' => $listing ? [
                'name' => $listing->getTranslation('name', 'en'),
                'type' => $listing->type->value,
                'region' => $listing->region,
            ] : null,
            'store_url' => route('claim.store', $token),
            'decline_url' => route('claim.decline.show', $token),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $partner = Partner::where('claim_token', $token)->firstOrFail();

        if ($partner->claimed_at) {
            return redirect()->route('claim.show', $token)
                ->with('flash', ['type' => 'error', 'message' => 'This listing has already been claimed.']);
        }

        $partner->update([
            'user_id' => $request->user()->id,
            'claimed_at' => now(),
            'claim_rejected_at' => null,
        ]);

        $partner->listings()->update(['claim_status' => 'claimed']);

        // Partner.user_id above is the inverse link and doesn't grant anything by
        // itself — User::canAccessPanel() gates Filament partner-portal access on
        // User.partner_id, so that's the one that actually has to be set for the
        // owner to be able to log in and connect their booking system.
        $request->user()->update(['partner_id' => $partner->id]);

        Notification::make()
            ->title("Welcome! You've claimed {$partner->name} on NamibWay.")
            ->body('Manage your listing, photos, and booking system connection here.')
            ->success()
            ->send();

        return redirect()->route('filament.partner.pages.dashboard');
    }

    public function declineShow(string $token): Response
    {
        $partner = Partner::where('claim_token', $token)->firstOrFail();

        $listing = $partner->listings()->first();

        return Inertia::render('ClaimDecline', [
            'partner' => [
                'name' => $partner->name,
                'claimed_at' => $partner->claimed_at?->toDateString(),
                'rejected_at' => $partner->claim_rejected_at?->toDateString(),
            ],
            'listing' => $listing ? [
                'name' => $listing->getTranslation('name', 'en'),
                'type' => $listing->type->value,
            ] : null,
            'decline_url' => route('claim.decline', $token),
            'claim_url' => route('claim.show', $token),
        ]);
    }

    public function decline(string $token): RedirectResponse
    {
        $partner = Partner::where('claim_token', $token)->firstOrFail();

        if (! $partner->claimed_at && ! $partner->claim_rejected_at) {
            $partner->update(['claim_rejected_at' => now()]);

            $partner->listings()->update([
                'claim_status' => 'rejected',
                'is_published' => false,
                'accepts_inquiries' => false,
            ]);

            Mail::to(config('mail.sales_address'))->queue(new PartnerDeclinedListing($partner));
        }

        return redirect()->route('claim.decline.show', $token);
    }
}

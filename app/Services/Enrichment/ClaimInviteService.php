<?php

namespace App\Services\Enrichment;

use App\Models\Partner;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends (or re-sends) the "claim your listing" email to a partner. Used by
 * the namibway:send-claim-emails batch command and the enrichment
 * dashboard's "Invite Owner" bulk action.
 */
class ClaimInviteService
{
    /** Generates a claim token on demand if the partner doesn't already have one. */
    public function invite(Partner $partner): bool
    {
        if (blank($partner->email) || $partner->claimed_at !== null || $partner->claim_rejected_at !== null) {
            return false;
        }

        if (blank($partner->claim_token)) {
            $partner->update(['claim_token' => Str::random(48)]);
        }

        $listing = $partner->listings->first() ?? $partner->listings()->first();

        Mail::send(
            'emails.claim-listing',
            [
                'partner' => $partner,
                'listing' => $listing,
                'claimUrl' => $this->claimUrl($partner),
                'declineUrl' => $this->declineUrl($partner),
                'listingUrl' => $listing?->is_published ? route('listings.show', $listing->slug) : null,
            ],
            function ($message) use ($partner) {
                $message
                    ->to($partner->email, $partner->name)
                    ->from(config('mail.from.address'), 'NamibWay')
                    ->subject('Your property on NamibWay — claim your free listing');
            }
        );

        $partner->update(['claim_token_sent_at' => now()]);

        return true;
    }

    public function claimUrl(Partner $partner): string
    {
        return url("/claim/{$partner->claim_token}");
    }

    public function declineUrl(Partner $partner): string
    {
        return url("/claim/{$partner->claim_token}/decline");
    }
}

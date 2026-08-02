<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
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
                'listingUrl' => $listing ? $this->listingUrl($listing, $partner) : null,
            ],
            function ($message) use ($partner) {
                $message
                    ->to($partner->email, $partner->name)
                    // Same address the POP3 fetcher polls (services.pop3.username) —
                    // a reply naturally lands back in the mailbox
                    // namibway:fetch-partner-emails reads from.
                    ->from(config('services.pop3.username') ?: config('mail.from.address'), 'NamibWay')
                    ->subject('Your property on NamibWay — claim your free listing');
            }
        );

        $partner->update(['claim_token_sent_at' => now()]);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listing?->id,
            'sent_by' => auth()->id(),
            'direction' => PartnerMessage::DIRECTION_OUTBOUND,
            'template' => 'claim_invite',
            'subject' => 'Your property on NamibWay — claim your free listing',
            'body' => "Claim link: {$this->claimUrl($partner)}",
            'sent_at' => now(),
        ]);

        return true;
    }

    /**
     * The claim_token doubles as a preview key (see ListingController::hasValidPreviewToken())
     * that does two things: bypasses the "unpublished listings 404" gate for drafts, and — for
     * published listings too — is what grants the owner's Edit/Publish/Unpublish controls
     * (ListingController::show()'s can_publish). Always include it, or a claimed/published
     * listing's owner link silently loses all edit access.
     */
    public function listingUrl(Listing $listing, Partner $partner): string
    {
        return route('listings.show', $listing->slug).'?preview='.$partner->claim_token;
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

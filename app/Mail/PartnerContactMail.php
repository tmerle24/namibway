<?php

namespace App\Mail;

use App\Models\Listing;
use App\Models\MessageSettings;
use App\Models\Partner;
use App\Services\Enrichment\ClaimInviteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PartnerContactMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Partner $partner,
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?Listing $listing = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Same address the POP3 fetcher polls (services.pop3.username) — a
            // reply from the partner's own mail client naturally lands back in
            // the mailbox namibway:fetch-partner-emails reads from.
            from: new Address(
                config('services.pop3.username') ?: config('mail.from.address'),
                config('mail.from.name'),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $inviter = app(ClaimInviteService::class);

        // A message sent from a specific listing's tab links just that one;
        // sent from the partner's own cumulated thread, it links all of them.
        $listings = $this->listing !== null ? collect([$this->listing]) : $this->partner->listings;

        return new Content(
            markdown: 'emails.partner.contact',
            with: [
                'bodyText' => $this->bodyText,
                'signature' => MessageSettings::current()->getSignatureOrDefault(),
                'listingLinks' => $this->buildListingLinks($listings, $inviter),
                'claimUrl' => $this->canOfferClaimLink() ? $inviter->claimUrl($this->partner) : null,
            ],
        );
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array<int, array{name: string|null, url: string|null, published: bool}>
     */
    private function buildListingLinks(Collection $listings, ClaimInviteService $inviter): array
    {
        return $listings->map(fn (Listing $listing): array => [
            'name' => $listing->name,
            // listingUrl() always needs a claim_token (it doubles as the
            // owner's edit/preview key) — without one there's nothing to link to.
            'url' => filled($this->partner->claim_token) ? $inviter->listingUrl($listing, $this->partner) : null,
            'published' => $listing->is_published,
        ])->all();
    }

    private function canOfferClaimLink(): bool
    {
        return $this->partner->claimed_at === null
            && $this->partner->claim_rejected_at === null
            && filled($this->partner->claim_token);
    }
}

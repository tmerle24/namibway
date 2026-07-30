<?php

namespace App\Mail;

use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerDeclinedListing extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public readonly ?Listing $listing;

    public function __construct(public readonly Partner $partner)
    {
        $this->listing = $partner->listings()->first();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Opted out — {$this->partner->name} declined their NamibWay listing",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.partner.declined-listing',
        );
    }
}

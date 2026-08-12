<?php

namespace App\Mail;

use App\Models\WebsiteSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Somebody has ordered a website" — to us, not to them.
 *
 * The order is a request and not a purchase: there is no payment provider for
 * this market, billing runs by invoice, and a person decides they are a
 * customer. That person has to find out, and nobody watches an admin panel for
 * a row that might appear.
 */
class WebsiteOrdered extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly WebsiteSubscription $subscription) {}

    public function envelope(): Envelope
    {
        // The relation is not nullable: the subscription is deleted with its
        // partner (see the migration's cascade).
        $partner = $this->subscription->partner->name;

        return new Envelope(subject: "Website ordered: {$partner}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.sites.website-ordered');
    }
}

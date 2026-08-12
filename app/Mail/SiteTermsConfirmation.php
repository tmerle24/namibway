<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * "Here is your website — please read these and confirm."
 *
 * This is the honest version of a box in our own panel. Publishing a site puts
 * somebody's name on a page we wrote, so the confirmation ought to come from
 * them; ticking it on their behalf records what we were told rather than what
 * they did.
 */
class SiteTermsConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public readonly string $confirmUrl;

    public function __construct(public readonly Site $site)
    {
        // Long enough that a business owner can come back to it after a week on
        // the road, and not open for ever: this link accepts a contract.
        $this->confirmUrl = URL::signedRoute(
            'sites.terms.confirm',
            ['site' => $site->id],
            now()->addDays(30),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your website is ready to publish: {$this->site->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.sites.terms-confirmation');
    }
}

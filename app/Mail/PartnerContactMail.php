<?php

namespace App\Mail;

use App\Models\MessageSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerContactMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
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
        return new Content(
            markdown: 'emails.partner.contact',
            with: ['bodyText' => $this->bodyText, 'signature' => MessageSettings::current()->getSignatureOrDefault()],
        );
    }
}

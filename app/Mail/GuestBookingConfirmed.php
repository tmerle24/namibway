<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestBookingConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string|null  $paymentUrl  Where the guest pays their deposit, where the
     *                                   property asked for it in the same click.
     * @param  string|null  $partnerMessage  What the property wanted to say alongside.
     *                                       Shown as their words, not ours.
     */
    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $partnerMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Booking confirmed: {$this->inquiry->sellerName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.inquiries.guest-confirmed',
        );
    }
}

<?php

namespace App\Mail;

use App\Models\PaymentIntent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Here is where you pay", for a stay that already exists.
 *
 * The confirmation carries its own payment button (GuestBookingConfirmed), so
 * this is the other case: a booking taken at the desk, a deposit asked for
 * later, a balance before arrival. What it must never read as is a second
 * confirmation — the guest has had that.
 */
class GuestPaymentRequest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PaymentIntent $intent,
        public readonly string $paymentUrl,
        public readonly ?string $partnerMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        $property = $this->intent->reservation?->listing?->name ?? config('app.name');

        return new Envelope(
            subject: "Payment request: {$property}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payments.request',
        );
    }
}

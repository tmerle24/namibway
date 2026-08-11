<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirmation that a stay was cancelled, so the guest has it in writing.
 *
 * Sent for a late cancellation too. Whether a penalty applies is between the
 * lodge and the guest — this says what happened, not what it costs, because
 * inventing a penalty the property never agreed to would be worse than saying
 * nothing.
 *
 * Not queued itself: it is only ever sent from NotifyAboutStay, which is
 * already a queued job. Queueing a mailable from inside a queued job is a
 * second hop that buys nothing and makes a failure harder to read.
 */
class GuestStayCancelled extends Mailable
{

    public function __construct(public readonly Reservation $reservation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking cancelled — '.$this->reservation->reference,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.stays.guest-cancelled');
    }
}

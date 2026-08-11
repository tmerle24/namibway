<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * What the guest gets when a lodge takes their booking.
 *
 * Distinct from GuestBookingConfirmed, which is about an Inquiry — a request
 * that a partner may still decline. This one is about a Reservation: a stay
 * that holds real inventory and has a reference the guest can quote at the
 * door. The two are not the same event and must not be merged.
 *
 * Everything shown is read off the stay rather than recomputed: the amounts
 * were frozen at booking, and so was what the rate included. See
 * BOOKING_SYSTEM.md, rule 4.
 *
 * Not queued itself: it is only ever sent from NotifyAboutStay, which is
 * already a queued job. Queueing a mailable from inside a queued job is a
 * second hop that buys nothing and makes a failure harder to read.
 */
class GuestStayConfirmed extends Mailable
{
    public function __construct(public readonly Reservation $reservation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your booking at '.($this->reservation->listing->name ?? 'your stay')
                .' — '.$this->reservation->reference,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.stays.guest-confirmed');
    }
}

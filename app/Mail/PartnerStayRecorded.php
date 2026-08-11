<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The lodge's copy: a stay was entered on the calendar.
 *
 * Sent for bookings the property typed in themselves as well as ones that
 * arrive from elsewhere. That is deliberate — a second person at the same desk
 * needs to know, and a printed copy is how most properties here still keep the
 * day's book.
 *
 * Not queued itself: it is only ever sent from NotifyAboutStay, which is
 * already a queued job. Queueing a mailable from inside a queued job is a
 * second hop that buys nothing and makes a failure harder to read.
 */
class PartnerStayRecorded extends Mailable
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?string $notice = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New booking: '.$this->reservation->guest_name
                .' — '.$this->reservation->check_in->isoFormat('D MMM'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.stays.partner-recorded');
    }
}

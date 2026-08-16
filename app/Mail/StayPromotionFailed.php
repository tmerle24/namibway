<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A booking that was confirmed to a guest but could not be written onto the
 * property's calendar.
 *
 * Goes to the team, never to the guest or the lodge: from the guest's side
 * nothing is wrong — they have a confirmation — and from the property's side
 * the fix is ours. It is an alert precisely because the alternative is finding
 * out when somebody arrives for a room the calendar says is free.
 */
class StayPromotionFailed extends Mailable
{
    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmed booking not on the calendar: '.$this->inquiry->name
                .' at '.$this->inquiry->sellerName(),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.stays.promotion-failed');
    }
}

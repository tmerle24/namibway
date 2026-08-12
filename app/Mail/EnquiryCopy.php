<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The guest's own copy of what they just sent, straight away.
 *
 * Not a confirmation — the business has not answered yet, and saying anything
 * that sounds like a booking would be a lie the business then has to correct.
 * What this is for: the visitor filled in a form on a small lodge's website and
 * has nothing to show they did. This is the receipt, with what they wrote, so
 * they can chase it themselves if nobody comes back.
 *
 * The confirmation or the refusal follows separately, when the business presses
 * one of the two buttons in its own mail.
 */
class EnquiryCopy extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Inquiry $inquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your enquiry to '.$this->inquiry->listing->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.inquiries.copy');
    }
}

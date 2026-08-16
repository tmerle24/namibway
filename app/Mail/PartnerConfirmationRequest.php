<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class PartnerConfirmationRequest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public readonly string $confirmUrl;

    public readonly string $cancelUrl;

    /**
     * Confirm and ask for the deposit in one press. It opens a page rather than
     * acting on arrival — see PartnerController::showConfirmWithPayment — which
     * is also where the property can add a message to the guest.
     */
    public readonly string $confirmWithPaymentUrl;

    public function __construct(public readonly Inquiry $inquiry)
    {
        $this->confirmUrl = URL::signedRoute(
            'partner.inquiries.confirm',
            ['inquiry' => $inquiry->id],
            now()->addDays(3),
        );

        $this->confirmWithPaymentUrl = URL::signedRoute(
            'partner.inquiries.confirm-with-payment',
            ['inquiry' => $inquiry->id],
            now()->addDays(3),
        );

        $this->cancelUrl = URL::signedRoute(
            'partner.inquiries.cancel',
            ['inquiry' => $inquiry->id],
            now()->addDays(3),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Booking request — please confirm: {$this->inquiry->sellerName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.partner.confirmation-request',
        );
    }
}

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

/**
 * Notifies the trader that a new order has arrived.
 *
 * Sent once the payment state is resolved: immediately for cash orders, after
 * the customer confirms the simulated payment for demo payments. The trader
 * sees the items, the total, the customer's contact, the payment status, and
 * the signed confirm/decline links — the same links every other inquiry uses.
 *
 * NamibWay never names itself as seller and never implies it handled the money.
 * The subject and body name the site (the trader's business), and the payment
 * status is worded to show who holds or will hold the money.
 */
class TraderOrderReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public readonly string $confirmUrl;

    public readonly string $cancelUrl;

    public function __construct(public readonly Inquiry $inquiry)
    {
        $this->confirmUrl = URL::signedRoute(
            'partner.inquiries.confirm',
            ['inquiry' => $inquiry->id],
            now()->addHours(48),
        );

        $this->cancelUrl = URL::signedRoute(
            'partner.inquiries.cancel',
            ['inquiry' => $inquiry->id],
            now()->addHours(48),
        );
    }

    public function envelope(): Envelope
    {
        $seller = $this->inquiry->sellerName();
        $customer = $this->inquiry->name;

        return new Envelope(
            subject: "New order from {$customer} — {$seller}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.trader.order-received',
        );
    }
}

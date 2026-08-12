<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Services\Payments\Providers\DTOs\ProviderCallback;
use App\Services\Payments\Providers\DTOs\ProviderIntent;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use Illuminate\Http\Request;

/**
 * One payment gateway, as everything above it sees one.
 *
 * The interface is small on purpose and the reason is written into
 * PAYMENTS.md § 5: **no provider has ever run against a real account**, ours
 * is a decision that has not been made, and the candidate list is long enough
 * (DPO Pay, Peach, PayGate, Ozow, Netcash, PayToday, a Namibian bank's own
 * acquiring, Paystack) that an abstraction whose only implementation is one
 * gateway will not survive contact with the second.
 *
 * So: four operations, and nothing above this file names a provider.
 *
 * ## Why authorise-then-capture rather than one call
 *
 * Because the asynchronous callback is where real integrations break, and a
 * design that collapses the two hides it. A guest is redirected away, the
 * gateway posts to us, and the guest comes back — in either order, sometimes
 * neither. Everything above this interface therefore has to cope with
 * settlement arriving before the guest returns, which is exactly what
 * DemoProvider makes happen on purpose.
 */
interface PaymentProvider
{
    /** The value stored in `payment_intents.provider`. Stable across renames. */
    public function key(): string;

    /** What a lodge or an admin sees in a settings screen. */
    public function label(): string;

    /**
     * Currencies this gateway will actually settle.
     *
     * Load-bearing rather than informational: the folio is in NAD and no
     * provider available to us settles NAD, so somebody has to decide what is
     * really charged. Answering it here keeps that decision inside the
     * provider instead of leaking into the booking flow.
     *
     * @return array<int, string> ISO 4217 codes
     */
    public function supportedCurrencies(): array;

    /**
     * Start an attempt: tell the gateway what is owed and get back somewhere
     * to send the guest.
     */
    public function createIntent(PaymentIntent $intent): ProviderIntent;

    /**
     * Ask the gateway what actually happened.
     *
     * Called when the guest comes back from the redirect, and again from the
     * callback path where a provider's webhook is not trusted on its own.
     * Must be safe to call repeatedly and after the intent is already
     * settled — "what happened" is a question, not a state change.
     */
    public function capture(PaymentIntent $intent): ProviderOutcome;

    /**
     * Send money back. `$amount` is positive and in the folio's currency;
     * the provider converts if it charged in another.
     */
    public function refund(Payment $payment, float $amount): ProviderOutcome;

    /**
     * Turn a webhook into something the application understands, or null if
     * this request is not one of ours.
     *
     * Returning null rather than throwing, because a gateway posts events we
     * do not care about and a 500 on those is how a webhook gets disabled at
     * the other end. Verifying the request really came from the gateway is
     * this method's job and nobody else's.
     */
    public function parseCallback(Request $request): ?ProviderCallback;
}

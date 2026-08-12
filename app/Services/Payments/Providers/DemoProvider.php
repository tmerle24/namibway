<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentIntentStatus;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Services\Payments\Providers\DTOs\ProviderCallback;
use App\Services\Payments\Providers\DTOs\ProviderIntent;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use Illuminate\Http\Request;

/**
 * A gateway that works completely, in-process, with no network and no account.
 *
 * The requirement it exists for (PAYMENTS.md § 5): the whole flow has to work
 * end to end *before* a real provider is chosen — authorise, capture, fail,
 * refund, and the asynchronous callback. Two things fall out of that, both
 * good. A prospect sees their own lodge taking a deposit in the demo tenant
 * `booking:demo-tenant` already builds. And when a real gateway is picked it
 * is one class and a configuration entry, because nothing above the interface
 * was ever written against a particular one.
 *
 * ## It is deliberately awkward in the same places a real gateway is
 *
 * A demo that always succeeds instantly teaches the wrong shape. This one:
 *
 * - hosts its own checkout page inside the app, with **pay**, **decline** and
 *   **abandon** buttons, so a failed payment is something anybody can produce
 *   on demand rather than a branch nobody ever exercises;
 * - fires its callback **before** the guest is redirected back, because that
 *   ordering is where real integrations break and a demo that hides it is
 *   worth less than no demo;
 * - can deliver the same callback twice, which a real gateway will.
 *
 * No credentials, no `verified_at`, nothing to configure. That is the point:
 * it works on a laptop with no internet.
 */
class DemoProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo (no real money)';
    }

    /**
     * Everything. A demo that refused a currency would be simulating a
     * limitation it does not have, and the currency question is real enough
     * without inventing one.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return (array) config('currencies.supported', ['NAD']);
    }

    public function createIntent(PaymentIntent $intent): ProviderIntent
    {
        // The checkout page lives in this application, which is what makes
        // the demo work with no network at all.
        return new ProviderIntent(
            redirectUrl: route('payments.checkout', $intent),
            providerReference: 'demo_'.$intent->reference,
            meta: ['hosted_by' => 'demo'],
        );
    }

    /**
     * What the guest decided, which the checkout page has already written onto
     * the intent.
     *
     * Reading the stored status rather than keeping state in the class is what
     * makes this behave like a real gateway: the answer survives a different
     * process asking, which is exactly the situation a webhook and a returning
     * browser are in.
     */
    public function capture(PaymentIntent $intent): ProviderOutcome
    {
        $decision = $intent->meta['decision'] ?? null;

        return match ($decision) {
            'pay' => ProviderOutcome::succeeded($intent->provider_reference ?? 'demo_'.$intent->reference),
            'decline' => ProviderOutcome::failed('The demo card was declined.'),
            'abandon' => ProviderOutcome::abandoned('The guest left the demo checkout.'),
            default => ProviderOutcome::pending('The guest has not decided yet.'),
        };
    }

    /**
     * Always succeeds. A demo refund that could fail would be simulating a
     * gateway's dispute rules, which differ per gateway and would therefore
     * teach something specifically wrong.
     */
    public function refund(Payment $payment, float $amount): ProviderOutcome
    {
        return ProviderOutcome::succeeded('demo_refund_'.$payment->id);
    }

    /**
     * The demo's webhook. No signature, because there is no shared secret and
     * pretending otherwise would suggest the route is protected when the real
     * protection is that this provider is never enabled in production.
     */
    public function parseCallback(Request $request): ?ProviderCallback
    {
        $reference = $request->input('reference');
        $decision = $request->input('decision');

        if (! is_string($reference) || ! is_string($decision)) {
            return null;
        }

        $outcome = match ($decision) {
            'pay' => ProviderOutcome::succeeded('demo_'.$reference),
            'decline' => ProviderOutcome::failed('The demo card was declined.'),
            'abandon' => ProviderOutcome::abandoned(),
            default => new ProviderOutcome(PaymentIntentStatus::Pending),
        };

        return new ProviderCallback($reference, $outcome);
    }
}

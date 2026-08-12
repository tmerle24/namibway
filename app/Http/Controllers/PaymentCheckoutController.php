<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Services\Payments\MoneyWriteGuard;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The demo gateway's own hosted checkout, inside this application.
 *
 * Only the demo provider ever reaches here — a real gateway hosts its own page
 * and this route would never be its redirect target. That is checked rather
 * than assumed, because a page that takes a "pay" click without taking money
 * must not be reachable for a real transaction under any circumstances.
 *
 * ## Why the callback fires before the redirect
 *
 * Because that is the order real integrations get wrong. A gateway's webhook
 * routinely lands while the guest's browser is still following a redirect, and
 * a system that only works when the guest comes back first is a system that
 * loses payments in production. Making the demo do it deliberately means the
 * code above it is exercised in the awkward order every single time.
 */
class PaymentCheckoutController extends Controller
{
    /** The page a guest lands on: what they owe, and three buttons. */
    public function show(PaymentIntent $intent): View|RedirectResponse
    {
        abort_unless($intent->provider === 'demo', 404);

        // An attempt that is already answered has nothing to ask. Send them
        // to the result rather than letting them pay twice.
        if (! $intent->status->isOpen()) {
            return redirect()->route('payments.return', $intent);
        }

        return view('payments.demo-checkout', [
            'intent' => $intent->load('reservation.listing'),
        ]);
    }

    /**
     * A button was pressed.
     *
     * The decision is written onto the intent and then delivered through the
     * *callback* path — not applied here — so the demo goes through the same
     * asynchronous door a real gateway uses. Settling directly would make the
     * demo prove something the real flow does not do.
     */
    public function decide(Request $request, PaymentIntent $intent, PaymentGateway $gateway): RedirectResponse
    {
        abort_unless($intent->provider === 'demo', 404);

        $decision = (string) $request->input('decision');

        abort_unless(in_array($decision, ['pay', 'decline', 'abandon'], true), 422);

        if ($intent->status->isOpen()) {
            MoneyWriteGuard::allow(function () use ($intent, $decision): void {
                $intent->meta = array_merge($intent->meta ?? [], ['decision' => $decision]);
                $intent->save();
            });

            // The callback, arriving before the guest gets back. See the class
            // comment — this ordering is the whole point of the exercise.
            $gateway->settle($intent->refresh());
        }

        return redirect()->route('payments.return', $intent);
    }
}

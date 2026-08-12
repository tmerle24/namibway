<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Services\Payments\Folio;
use App\Services\Payments\PaymentGateway;
use Illuminate\View\View;

/**
 * Where a guest lands after paying — or after not paying.
 *
 * It settles again on arrival, and that is not belt-and-braces: the webhook may
 * not have arrived, may have been dropped, or may be behind a queue. Nothing
 * here assumes a callback happened, and nothing double-counts if one did —
 * `payments.payment_intent_id` is unique, so the second settle is a no-op.
 *
 * No authentication. The reference in the URL is a long random string and is
 * the only thing that identifies this page, which is the same arrangement the
 * saved-plan tokens use: a link mailed to a guest cannot demand they have an
 * account, and CLAUDE.md's account line puts booking behind one but not
 * *paying an already-issued request*.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(PaymentIntent $intent, PaymentGateway $gateway, Folio $folio): View
    {
        $settled = $gateway->settle($intent);
        $reservation = $settled->reservation;

        return view('payments.result', [
            'intent' => $settled,
            'reservation' => $reservation,
            'statement' => $reservation === null ? null : $folio->for($reservation),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use Illuminate\Http\RedirectResponse;

/**
 * Receives the browser redirect back from PayGate after the guest has paid
 * (or cancelled or failed).
 *
 * PayGate sends the guest back via a form POST (browser-mediated, not
 * server-to-server) to the RETURN_URL set in initiate.trans. We do not read
 * the POST body here — `PaymentReturnController` calls `capture()` →
 * `query.trans`, which is the only source of truth. This controller's only
 * job is to bounce the guest to that GET route without any CSRF requirements.
 *
 * CSRF-exempt: declared in bootstrap/app.php because PayGate's redirect is a
 * cross-domain POST with no session and no CSRF token.
 */
class PayGateReturnController extends Controller
{
    public function __invoke(PaymentIntent $intent): RedirectResponse
    {
        return redirect()->route('payments.return', $intent);
    }
}

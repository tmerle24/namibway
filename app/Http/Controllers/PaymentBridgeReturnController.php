<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use Illuminate\Http\RedirectResponse;

/**
 * Receives the browser redirect back from a payment gateway after the guest has
 * paid (or cancelled or failed).
 *
 * Some gateways send the guest back via a form POST (browser-mediated, not
 * server-to-server) to the RETURN_URL. We do not trust or read the POST body
 * here — `PaymentReturnController` calls `capture()` on the provider, which is
 * the only source of truth. This controller's only job is to bounce the guest
 * to that GET route without any CSRF requirements.
 *
 * CSRF-exempt: declared in bootstrap/app.php because the gateway's redirect is
 * a cross-domain POST with no session and no CSRF token.
 */
class PaymentBridgeReturnController extends Controller
{
    public function __invoke(PaymentIntent $intent): RedirectResponse
    {
        return redirect()->route('payments.return', $intent);
    }
}

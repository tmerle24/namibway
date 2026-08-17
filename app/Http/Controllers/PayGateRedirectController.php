<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Services\Payments\Providers\PayGateClient;
use Illuminate\View\View;

/**
 * Serves the auto-submitting bridge page that sends the guest to PayGate.
 *
 * PayGate's process.trans requires a form POST (not a GET redirect), so we
 * cannot hand the guest a plain URL. This page renders a form with the
 * PAY_REQUEST_ID and CHECKSUM hidden fields and submits it immediately via a
 * small inline script. The guest sees a blank flash before landing on PayGate's
 * hosted payment page.
 *
 * No authentication. The intent reference in the URL is the guest's access
 * credential — the same arrangement as the payment return page.
 */
class PayGateRedirectController extends Controller
{
    public function __invoke(PaymentIntent $intent): View
    {
        abort_unless($intent->provider === 'paygate', 404);

        $payRequestId = $intent->meta['pay_request_id'] ?? null;
        $checksum = $intent->meta['process_checksum'] ?? null;

        abort_unless(
            is_string($payRequestId) && $payRequestId !== '' && is_string($checksum) && $checksum !== '',
            400,
        );

        return view('payments.paygate-redirect', [
            'processUrl' => PayGateClient::PROCESS_URL,
            'payRequestId' => $payRequestId,
            'checksum' => $checksum,
        ]);
    }
}

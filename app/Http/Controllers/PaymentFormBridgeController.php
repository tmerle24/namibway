<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use Illuminate\View\View;

/**
 * Serves the auto-submitting bridge page for the form-POST redirect flow.
 *
 * Some payment gateways require a form POST (not a GET redirect) to send the
 * guest to their hosted payment page. This page renders a form with the
 * required hidden fields and submits it immediately via a small inline script.
 * The guest sees a brief flash before landing on the gateway's payment page.
 *
 * The process URL and form fields come from the intent's meta, written there
 * by the provider when the intent was created.
 *
 * No authentication. The intent reference in the URL is the guest's access
 * credential — the same arrangement as the payment return page.
 */
class PaymentFormBridgeController extends Controller
{
    public function __invoke(PaymentIntent $intent): View
    {
        abort_unless($intent->provider === 'paygate', 404);

        $processUrl = $intent->meta['process_url'] ?? null;
        $payRequestId = $intent->meta['pay_request_id'] ?? null;
        $checksum = $intent->meta['process_checksum'] ?? null;

        abort_unless(
            is_string($processUrl) && $processUrl !== ''
            && is_string($payRequestId) && $payRequestId !== ''
            && is_string($checksum) && $checksum !== '',
            400,
        );

        return view('payments.paygate-redirect', [
            'processUrl' => $processUrl,
            'payRequestId' => $payRequestId,
            'checksum' => $checksum,
        ]);
    }
}

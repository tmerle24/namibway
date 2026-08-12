<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Providers\PaymentProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Where a gateway posts what happened.
 *
 * Three rules, all of them learned from how webhooks go wrong:
 *
 * **Answer 200 to anything you understood.** A gateway that gets an error
 * retries, and then stops retrying, and then disables the endpoint. An event
 * we do not care about is not an error.
 *
 * **Verify inside the provider.** Only the provider knows how its signature
 * works, so `parseCallback()` returns null for anything it cannot vouch for —
 * and this route treats null as "not for us" rather than as a failure, because
 * a signature check that answers 403 tells an attacker they found the right
 * URL.
 *
 * **Never trust the body for the amount.** The callback says *which* attempt
 * and *roughly* what happened; PaymentGateway then asks the gateway directly.
 * A webhook that could set an amount is a webhook that can be forged into a
 * paid booking.
 *
 * CSRF-exempt by virtue of living outside the web middleware group — a gateway
 * has no session and no token.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentProviderFactory $providers,
        PaymentGateway $gateway,
    ): JsonResponse {
        try {
            $implementation = $providers->make($provider);
        } catch (InvalidArgumentException) {
            // An unknown provider in the URL is somebody probing, not a
            // gateway. 404 rather than 500, and nothing logged loudly.
            return response()->json(['received' => false], 404);
        }

        $callback = $implementation->parseCallback($request);

        if ($callback === null) {
            // Either an event we do not handle or one we could not verify.
            // Both are 200: a gateway that gets errors stops delivering.
            return response()->json(['received' => true]);
        }

        $intent = PaymentIntent::query()
            ->where('provider', $implementation->key())
            ->where(fn ($query) => $query
                ->where('reference', $callback->intentReference)
                ->orWhere('provider_reference', $callback->intentReference))
            ->first();

        if (! $intent instanceof PaymentIntent) {
            Log::warning('A payment callback arrived for an attempt we do not have.', [
                'provider' => $implementation->key(),
                'reference' => $callback->intentReference,
            ]);

            return response()->json(['received' => true]);
        }

        $gateway->settle($intent, $callback->outcome);

        return response()->json(['received' => true]);
    }
}

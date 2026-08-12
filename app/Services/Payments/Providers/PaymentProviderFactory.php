<?php

namespace App\Services\Payments\Providers;

use InvalidArgumentException;

/**
 * Resolves the gateway — the same shape as ConnectorFactory, on purpose.
 *
 * Platform-level rather than per-partner today, and that is a deliberate
 * reading of who actually collects: under the split and merchant models the
 * money comes to *us*, so the merchant account is ours; under the agency model
 * the property collects outside the system entirely and there is nothing to
 * configure. A partner-configured PSP becomes real the day a property wants us
 * to collect into *their* account, and it is a `Partner.payment_provider`
 * column plus a branch here — the shape is already right, which is what
 * PAYMENTS.md § 5 asked for.
 *
 * The demo provider needs no credentials at all, which is the point: the whole
 * flow runs on a laptop with no internet and no merchant account.
 */
class PaymentProviderFactory
{
    public function default(): PaymentProvider
    {
        return $this->make((string) config('payments.default_provider', 'demo'));
    }

    public function make(string $key): PaymentProvider
    {
        return match ($key) {
            'demo' => new DemoProvider,
            'paystack' => $this->makePaystack(),
            default => throw new InvalidArgumentException(
                "Unknown payment provider [{$key}]. Configured in config/payments.php."
            ),
        };
    }

    /**
     * Every provider that could be selected, for a settings screen.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'demo' => (new DemoProvider)->label(),
            'paystack' => 'Paystack',
        ];
    }

    private function makePaystack(): PaystackProvider
    {
        $secret = (string) config('payments.providers.paystack.secret_key', '');

        if ($secret === '') {
            throw new InvalidArgumentException(
                'Paystack is selected but PAYSTACK_SECRET_KEY is not set. '
                .'Note that Paystack does not support Namibia as a merchant country — see PaystackProvider.'
            );
        }

        return new PaystackProvider(new PaystackClient(
            secretKey: $secret,
            baseUrl: (string) config('payments.providers.paystack.base_url', 'https://api.paystack.co'),
        ));
    }
}

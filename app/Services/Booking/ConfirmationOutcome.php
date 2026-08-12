<?php

namespace App\Services\Booking;

/**
 * What came of a partner confirming a request.
 *
 * A boolean was enough while confirming did one thing. Asking for a deposit in
 * the same click can half-succeed — the booking is confirmed and the guest has
 * been told, but no payment link went with it because the stay could not be put
 * on the calendar or there was nothing to charge for. That is not a failure to
 * swallow and not a reason to un-confirm: it is something the person who just
 * pressed the button has to be told, so it is carried back rather than logged.
 */
class ConfirmationOutcome
{
    private function __construct(
        public readonly bool $handled,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $problem = null,
    ) {}

    public static function alreadyHandled(): self
    {
        return new self(handled: false);
    }

    public static function confirmed(?string $paymentUrl = null, ?string $problem = null): self
    {
        return new self(handled: true, paymentUrl: $paymentUrl, problem: $problem);
    }

    /** Whether a payment link actually went out with the confirmation. */
    public function askedForPayment(): bool
    {
        return $this->paymentUrl !== null;
    }
}

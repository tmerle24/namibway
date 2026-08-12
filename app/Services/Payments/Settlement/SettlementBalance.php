<?php

namespace App\Services\Payments\Settlement;

use App\Support\Money;

/**
 * What is owed between us and a partner on one stay.
 *
 * One signed number rather than an amount plus a direction, because the
 * interesting case is the one where it is **zero** — model C with the deposit
 * set to the commission — and "zero, owed by nobody" is a direction that would
 * have to be invented. A signed amount says it without a third state.
 *
 * Positive: we owe the partner. Negative: the partner owes us.
 */
class SettlementBalance
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}

    public static function nothing(string $currency): self
    {
        return new self(0.0, $currency);
    }

    /** Nothing has to move at all — no payout, no invoice, no reconciliation. */
    public function isSettled(): bool
    {
        return Money::cents($this->amount) === 0;
    }

    public function isOwedToPartner(): bool
    {
        return Money::cents($this->amount) > 0;
    }

    public function isOwedToUs(): bool
    {
        return Money::cents($this->amount) < 0;
    }

    /** The sentence a statement would print. */
    public function describe(): string
    {
        if ($this->isSettled()) {
            return 'Nothing to move in either direction.';
        }

        return $this->isOwedToPartner()
            ? Money::format($this->amount, $this->currency).' to pay out to the property.'
            : Money::format(abs($this->amount), $this->currency).' to invoice the property.';
    }
}

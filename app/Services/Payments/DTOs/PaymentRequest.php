<?php

namespace App\Services\Payments\DTOs;

use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One money movement, as asked for. The recorder turns this into a row and
 * rewrites the folio; nothing else may.
 *
 * `amount` is in the folio's currency and is signed — negative is money going
 * back to the guest. The received_* trio is only for the case where the guest
 * handed over something else, and is validated as a set: an amount with no
 * currency is not a fact, it is half of one.
 */
class PaymentRequest
{
    public readonly Carbon $receivedAt;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly float $amount,
        public readonly PaymentMethod $method,
        public readonly PaymentCollector $collectedBy,
        ?CarbonInterface $receivedAt = null,
        public readonly ?PaymentStatus $status = null,
        public readonly ?float $receivedAmount = null,
        public readonly ?string $receivedCurrency = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?string $reference = null,
        public readonly ?int $recordedBy = null,
        public readonly ?string $note = null,
        /**
         * The provider attempt this came from, where it came from one. The
         * column is unique, which is what makes a gateway delivering the same
         * callback twice harmless.
         */
        public readonly ?int $paymentIntentId = null,
    ) {
        $this->receivedAt = $receivedAt === null
            ? Carbon::now()
            : Carbon::parse($receivedAt);

        $this->assertForeignCurrencyIsWholeOrAbsent();
    }

    /**
     * The status a payment starts in when the caller did not say.
     *
     * Cash and a card slip from the property's own terminal are certain the
     * moment they are entered; a bank transfer somebody has been told about is
     * not. Making the desk answer that question on every cash payment would be
     * a checkbox ticked without reading, which is worse than a default.
     */
    public function initialStatus(): PaymentStatus
    {
        return $this->status
            ?? ($this->method->isImmediate() ? PaymentStatus::Cleared : PaymentStatus::Recorded);
    }

    /** The folio's currency — what the stay is denominated in. */
    public function currency(): string
    {
        return strtoupper($this->reservation->currency);
    }

    /**
     * Either all three foreign-currency facts or none of them.
     *
     * PAYMENTS_BUILD.md guardrail 4: what was owed, what the guest handed over
     * and the rate used are three separate facts. Two of three is a row that
     * cannot be reconciled later, and the cheapest moment to refuse it is
     * before it is written.
     */
    private function assertForeignCurrencyIsWholeOrAbsent(): void
    {
        $given = array_filter(
            [$this->receivedAmount, $this->receivedCurrency, $this->exchangeRate],
            fn (mixed $value): bool => $value !== null,
        );

        if ($given === [] || count($given) === 3) {
            return;
        }

        throw new InvalidArgumentException(
            'A payment in another currency needs all three of received amount, received currency and exchange rate. '
            .'Two of the three cannot be reconciled later.'
        );
    }
}

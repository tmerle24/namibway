<?php

namespace App\Services\Payments\DTOs;

use App\Enums\ChargeKind;

/**
 * One line of a document, as it was issued.
 *
 * A value object rather than a model: these are written into the invoice's
 * `lines` column and read back out of it, and the whole point is that they
 * stop being connected to the rows they came from. A line that could still
 * reach back to its `ReservationCharge` would be a line that changes when the
 * charge does — which is exactly what an issued invoice must not do.
 *
 * `kind` distinguishes the stay from what was added on top, because "VAT" and
 * "Park permit" mean different things to a guest and to an accountant, and
 * because a lodge reconciling its NTB return has to find the levy without
 * reading every line. ChargeKind already draws that line; this reuses it
 * rather than inventing a parallel vocabulary.
 */
class InvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly float $amount,
        /** Null for the stay itself; a tax, levy or fee otherwise. */
        public readonly ?ChargeKind $kind = null,
        public readonly ?int $quantity = null,
        /** Set on a charge already inside the price, so it is shown and not added. */
        public readonly bool $isIncluded = false,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $kind = $row['kind'] ?? null;

        return new self(
            description: (string) ($row['description'] ?? ''),
            amount: (float) ($row['amount'] ?? 0),
            kind: is_string($kind) ? ChargeKind::tryFrom($kind) : null,
            quantity: isset($row['quantity']) ? (int) $row['quantity'] : null,
            isIncluded: (bool) ($row['is_included'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'amount' => round($this->amount, 2),
            'kind' => $this->kind?->value,
            'quantity' => $this->quantity,
            'is_included' => $this->isIncluded,
        ];
    }

    /** Whether this line adds to the total, as opposed to explaining part of it. */
    public function addsToTotal(): bool
    {
        return ! $this->isIncluded;
    }

    /** Whether the revenue authority's share is on this line. */
    public function isTax(): bool
    {
        return $this->kind === ChargeKind::Tax;
    }

    public function negated(): self
    {
        return new self(
            description: $this->description,
            amount: -$this->amount,
            kind: $this->kind,
            quantity: $this->quantity,
            isIncluded: $this->isIncluded,
        );
    }
}

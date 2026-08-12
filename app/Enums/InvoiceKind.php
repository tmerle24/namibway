<?php

namespace App\Enums;

/**
 * What the document is for.
 *
 * Three kinds and no more, because each answers a different question about who
 * owes whom: a stay invoice is the guest's, a commission invoice is the
 * partner's under the agency model, and a credit note takes one of those back.
 */
enum InvoiceKind: string
{
    /** What a guest owes for a stay. */
    case Stay = 'stay';

    /**
     * What a partner owes us, under the agency model where we collect nothing
     * and have to ask for our commission afterwards. Raised in slice 4; the
     * kind exists here so the numbering series does not have to be retrofitted
     * around it later.
     */
    case Commission = 'commission';

    /**
     * A correction. Its own document with its own number, pointing at the
     * invoice it corrects — an issued invoice is never edited, so this is the
     * only way a wrong one is put right.
     */
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Stay => 'Invoice',
            self::Commission => 'Commission invoice',
            self::CreditNote => 'Credit note',
        };
    }

    /** Whether the amounts on this kind of document are negative. */
    public function isCredit(): bool
    {
        return $this === self::CreditNote;
    }
}

<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Thrown when something tries to change or delete an invoice that has been
 * issued.
 *
 * There is no flag to turn this off, and adding one would be the bug. An
 * invoice that can be edited after issue is not a legal document; it is a
 * draft with a number on it, and the guarantees stacked on top of that number
 * — gaplessness, a credit note that means something, a VAT return that
 * reconciles — stop being true the moment the first row is quietly corrected.
 *
 * The way to fix a wrong invoice is a **credit note**: its own document, its
 * own number, pointing at this one. See App\Services\Payments\InvoiceIssuer.
 */
class IssuedInvoiceException extends RuntimeException
{
    public function __construct(string $number)
    {
        parent::__construct(
            "Invoice [{$number}] has been issued and cannot be changed or deleted. "
            .'Raise a credit note against it and issue a corrected invoice — the record of what was sent stays.'
        );
    }
}

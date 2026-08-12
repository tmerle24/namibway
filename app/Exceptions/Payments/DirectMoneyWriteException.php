<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Thrown when something tries to write a money table without going through
 * App\Services\Payments.
 *
 * If you are reading this from a stack trace: the fix is not to relax the
 * guard. Add the operation you need to the writer that owns the table —
 * PaymentRecorder for payments, InvoiceIssuer for invoices — because that is
 * where the folio totals, the reversal rules, the gapless numbering and the
 * immutability live, and a write that skips it silently skips those too. A
 * stay whose `paid_amount` no longer matches its payments is a bug found by a
 * guest at check-out; a second invoice with the same number is a bug found by
 * an auditor.
 */
class DirectMoneyWriteException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "[{$modelClass}] was written outside App\\Services\\Payments. "
            .'Money has exactly one write path — add the operation to the writer that owns the table '
            .'instead of writing the model directly.'
        );
    }
}

<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Thrown when something tries to write the payments table without going
 * through App\Services\Payments\PaymentRecorder.
 *
 * If you are reading this from a stack trace: the fix is not to relax the
 * guard. Add the operation you need to PaymentRecorder — that is where the
 * folio totals, the reversal rules and the immutability live, and a write
 * that skips it is a write that silently skips those too. A stay whose
 * `paid_amount` no longer matches its payments is a bug found by a guest at
 * check-out.
 */
class DirectPaymentWriteException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "[{$modelClass}] was written outside App\\Services\\Payments\\PaymentRecorder. "
            .'Money has exactly one write path — add the operation there instead of writing the model directly.'
        );
    }
}

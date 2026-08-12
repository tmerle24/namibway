<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\DirectPaymentWriteException;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes "every payment goes through PaymentRecorder" enforceable rather than
 * merely agreed. Deliberately the same shape as InventoryWriteGuard — a second
 * pattern for the same rule would be one more thing to learn and one more
 * place for the rule to be half-applied.
 *
 * The rule matters here for the reason it matters there, one step sharper:
 * everything a ledger later needs — invoices that reference payments, a
 * settlement model that reads who collected what, reconciliation against a
 * gateway — is a change *inside* the recorder if there is exactly one of them.
 * And a payment written from a Filament action that forgot to update
 * `reservations.paid_amount` leaves a stay that says it owes money it does
 * not, which nobody notices until somebody is asked to pay twice.
 *
 * Scope, stated plainly: this catches Eloquent saves and deletes, because
 * those fire model events. It does **not** catch mass updates through the
 * query builder, which fire none. The architecture test in
 * tests/Feature/Payments covers that half by refusing to let those calls
 * appear outside this namespace at all.
 */
class PaymentWriteGuard
{
    /** Depth rather than a boolean: the recorder calls itself when reversing. */
    private static int $depth = 0;

    /**
     * Run a callback with payment writes permitted.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function allow(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @throws DirectPaymentWriteException
     */
    public static function assertOpen(Model $model): void
    {
        if (self::isOpen()) {
            return;
        }

        throw new DirectPaymentWriteException($model::class);
    }
}

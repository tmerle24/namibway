<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\DirectMoneyWriteException;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes "money is written in one place" enforceable rather than merely agreed.
 * Deliberately the same shape as InventoryWriteGuard — a second pattern for
 * the same rule would be one more thing to learn and one more place for the
 * rule to be half-applied.
 *
 * One guard for the whole money domain rather than one per table. It started
 * out named after payments, because payments were all there was; invoices are
 * the same rule with sharper consequences (an invoice that can be written from
 * anywhere is an invoice that can be written *twice*, and gapless numbering
 * dies with it), and a third copy of this class for the next money table would
 * be how the rule quietly stops being one rule.
 *
 * Why it matters: everything the ledger later needs — settlement models that
 * read who collected what, reconciliation against a gateway, credit notes that
 * have to reference the invoice they correct — is a change *inside* one writer
 * if there is exactly one. And a payment written from a Filament action that
 * forgot to update `reservations.paid_amount` leaves a stay saying it owes
 * money it does not, which nobody notices until somebody is asked to pay twice.
 *
 * Scope, stated plainly: this catches Eloquent saves and deletes, because
 * those fire model events. It does **not** catch mass updates through the
 * query builder, which fire none. The architecture test in
 * tests/Feature/Payments covers that half by refusing to let those calls
 * appear outside this namespace at all.
 */
class MoneyWriteGuard
{
    /**
     * Depth rather than a boolean: the writers call themselves — the recorder
     * when reversing, the issuer when a credit note is raised against an
     * invoice — and a nested call must not close the gate on its way out.
     */
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
     * @throws DirectMoneyWriteException
     */
    public static function assertOpen(Model $model): void
    {
        if (self::isOpen()) {
            return;
        }

        throw new DirectMoneyWriteException($model::class);
    }
}

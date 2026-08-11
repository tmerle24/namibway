<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\DirectInventoryWriteException;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes "every inventory mutation goes through InventoryWriter" enforceable
 * rather than merely agreed.
 *
 * The rule matters because everything deliberately left out of this slice —
 * an append-only ledger, allotments, channel sync, offline replay — is a
 * change *inside* the writer if there is exactly one of them, and an
 * excavation if inventory is written from a controller here and a Filament
 * action there. The cheapest moment to hold the line is before the second
 * writer exists.
 *
 * Scope, stated plainly: this catches Eloquent saves and deletes, because
 * those fire model events. It does **not** catch mass updates through the
 * query builder (`Model::query()->update()`, `DB::table(...)->update()`),
 * which fire no events — and the writer itself uses exactly that for the
 * counters. The architecture test in tests/Feature/Inventory covers that
 * half by refusing to let those calls appear outside this namespace at all.
 */
class InventoryWriteGuard
{
    /**
     * Depth rather than a boolean: the writer calls itself (booking writes
     * calendar rows, cancelling releases them), and a nested call must not
     * close the gate on its way out.
     */
    private static int $depth = 0;

    /**
     * Run a callback with inventory writes permitted.
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
     * @throws DirectInventoryWriteException
     */
    public static function assertOpen(Model $model): void
    {
        if (self::isOpen()) {
            return;
        }

        throw new DirectInventoryWriteException($model::class);
    }
}

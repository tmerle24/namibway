<?php

namespace App\Exceptions\Inventory;

use RuntimeException;

/**
 * Thrown when something tries to write an inventory table without going
 * through App\Services\Inventory\InventoryWriter.
 *
 * If you are reading this from a stack trace: the fix is not to relax the
 * guard. Add the operation you need to InventoryWriter — that is where the
 * concurrency handling, the pricing and the calendar bookkeeping live, and a
 * write that skips it is a write that silently skips those too.
 */
class DirectInventoryWriteException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "[{$modelClass}] was written outside App\\Services\\Inventory\\InventoryWriter. "
            .'Inventory has exactly one write path — add the operation there instead of writing the model directly.'
        );
    }
}

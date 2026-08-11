<?php

namespace App\Models\Concerns;

use App\Services\Inventory\InventoryWriteGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to every model that holds inventory. See InventoryWriteGuard for
 * why the rule exists and what it does and does not catch.
 */
trait GuardsInventoryWrites
{
    public static function bootGuardsInventoryWrites(): void
    {
        static::saving(function (Model $model): void {
            InventoryWriteGuard::assertOpen($model);
        });

        static::deleting(function (Model $model): void {
            InventoryWriteGuard::assertOpen($model);
        });
    }
}

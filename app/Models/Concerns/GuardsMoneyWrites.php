<?php

namespace App\Models\Concerns;

use App\Services\Payments\MoneyWriteGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to every model that holds money. See MoneyWriteGuard for why the
 * rule exists and what it does and does not catch.
 */
trait GuardsMoneyWrites
{
    public static function bootGuardsMoneyWrites(): void
    {
        static::saving(function (Model $model): void {
            MoneyWriteGuard::assertOpen($model);
        });

        static::deleting(function (Model $model): void {
            MoneyWriteGuard::assertOpen($model);
        });
    }
}

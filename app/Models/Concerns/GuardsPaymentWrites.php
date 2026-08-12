<?php

namespace App\Models\Concerns;

use App\Services\Payments\PaymentWriteGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to every model that holds money. See PaymentWriteGuard for why the
 * rule exists and what it does and does not catch.
 */
trait GuardsPaymentWrites
{
    public static function bootGuardsPaymentWrites(): void
    {
        static::saving(function (Model $model): void {
            PaymentWriteGuard::assertOpen($model);
        });

        static::deleting(function (Model $model): void {
            PaymentWriteGuard::assertOpen($model);
        });
    }
}

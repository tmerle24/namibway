<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's own commission and deposit rates. One row — settings, not
 * records — following MessageSettings.
 *
 * Not guarded by MoneyWriteGuard, and that is deliberate rather than an
 * oversight: this holds *terms*, not money. Nothing here is a transaction, a
 * balance or a document, and the guard exists to keep those from being written
 * from a screen. This is written from a screen on purpose.
 *
 * @property int $id
 * @property float $commission_rate
 * @property float $deposit_rate
 * @property float|null $minimum_deposit_rate
 */
class PaymentSettings extends Model
{
    protected $fillable = [
        'commission_rate',
        'deposit_rate',
        'minimum_deposit_rate',
    ];

    protected $casts = [
        'commission_rate' => 'float',
        'deposit_rate' => 'float',
        'minimum_deposit_rate' => 'float',
    ];

    /**
     * Always the same row, seeded from config/payments.php the first time it
     * is read. The config values stay as the fallback rather than becoming
     * dead once this exists — a fresh database, a test, and a deploy that has
     * not run a seeder all have to answer the question.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'commission_rate' => (float) config('payments.commission_rate', 5.0),
            'deposit_rate' => (float) config('payments.deposit_rate', 15.0),
            'minimum_deposit_rate' => config('payments.minimum_deposit_rate'),
        ]);
    }

    /**
     * The lowest deposit a partner may set for themselves.
     *
     * Null means "the commission rate", stored as a meaning rather than as a
     * copied number so the floor follows the commission when that changes. At
     * exactly that floor nothing has to move between us and the partner, which
     * is the cheapest arrangement for both sides and therefore the one worth
     * making the natural landing spot.
     */
    public function effectiveMinimumDepositRate(): float
    {
        return $this->minimum_deposit_rate ?? $this->commission_rate;
    }
}

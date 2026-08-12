<?php

namespace App\Models;

use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\GuardsMoneyWrites;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One money movement against a stay. See the payments migration for why a
 * refund is a negative row here rather than anything else.
 *
 * Written only by App\Services\Payments\PaymentRecorder — the trait enforces
 * it at runtime and an architecture test enforces the half the trait cannot
 * see.
 *
 * @property int $id
 * @property int $reservation_id
 * @property float $amount
 * @property string $currency
 * @property float|null $received_amount
 * @property string|null $received_currency
 * @property float|null $exchange_rate
 * @property CarbonImmutable $received_at
 * @property PaymentMethod $method
 * @property PaymentCollector $collected_by
 * @property PaymentStatus $status
 * @property string|null $reference
 * @property int|null $reverses_payment_id
 * @property int|null $recorded_by
 * @property string|null $note
 * @property-read Reservation|null $reservation
 */
class Payment extends Model
{
    use GuardsMoneyWrites;

    protected $fillable = [
        'reservation_id',
        'amount',
        'currency',
        'received_amount',
        'received_currency',
        'exchange_rate',
        'received_at',
        'method',
        'collected_by',
        'status',
        'reference',
        'reverses_payment_id',
        'recorded_by',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'received_amount' => 'float',
        'exchange_rate' => 'float',
        'received_at' => 'datetime',
        'method' => PaymentMethod::class,
        'collected_by' => PaymentCollector::class,
        'status' => PaymentStatus::class,
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The payment this one takes back, where it takes one back.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reverses_payment_id');
    }

    /**
     * The reversal of this payment, if it has been reversed. HasOne rather
     * than HasMany because the unique index says there is at most one.
     *
     * @return HasOne<Payment, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Payment::class, 'reverses_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Money going back to the guest, whatever it is called on screen. */
    public function isRefund(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Whether this row took another one back, as opposed to being an ordinary
     * refund. Both are negative; only one points at a payment.
     */
    public function isReversal(): bool
    {
        return $this->reverses_payment_id !== null;
    }

    /**
     * What the guest actually handed over, in the words a receipt would use.
     * Null where they paid in the folio's own currency and there is nothing
     * to explain.
     */
    public function foreignAmountLabel(): ?string
    {
        if ($this->received_amount === null || $this->received_currency === null) {
            return null;
        }

        return Money::format($this->received_amount, $this->received_currency);
    }
}

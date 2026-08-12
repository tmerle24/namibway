<?php

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurpose;
use App\Models\Concerns\GuardsMoneyWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One attempt to take money through a provider. See the migration for why this
 * is not a payment.
 *
 * @property int $id
 * @property string $reference
 * @property int $reservation_id
 * @property string $provider
 * @property PaymentPurpose $purpose
 * @property PaymentIntentStatus $status
 * @property float $amount
 * @property string $currency
 * @property float $charge_amount
 * @property string $charge_currency
 * @property float $exchange_rate
 * @property string|null $provider_reference
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $completed_at
 * @property-read Reservation|null $reservation
 */
class PaymentIntent extends Model
{
    use GuardsMoneyWrites;

    protected $fillable = [
        'reference',
        'reservation_id',
        'provider',
        'purpose',
        'status',
        'amount',
        'currency',
        'charge_amount',
        'charge_currency',
        'exchange_rate',
        'provider_reference',
        'meta',
        'completed_at',
    ];

    protected $casts = [
        'purpose' => PaymentPurpose::class,
        'status' => PaymentIntentStatus::class,
        'amount' => 'float',
        'charge_amount' => 'float',
        'charge_currency' => 'string',
        'exchange_rate' => 'float',
        'meta' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The payment this attempt produced, if it produced one. HasOne because
     * the unique index says at most one — which is what makes a repeated
     * callback harmless.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** Whether the guest is being charged in a currency other than the folio's. */
    public function isConverted(): bool
    {
        return strtoupper($this->charge_currency) !== strtoupper($this->currency);
    }

    /** The page a guest is sent to. Route-model-bound on `reference`, never id. */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}

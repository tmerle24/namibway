<?php

namespace App\Models;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Models\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen charge on one stay. See the migration: this is a result, not a
 * calculation waiting to be repeated.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $charge_id
 * @property string $name
 * @property ChargeKind $kind
 * @property ChargeBasis $basis
 * @property float $rate
 * @property float $base_amount
 * @property float $amount
 * @property bool $is_included
 * @property string $currency
 * @property int $sort
 */
class ReservationCharge extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'reservation_id',
        'charge_id',
        'name',
        'kind',
        'basis',
        'rate',
        'base_amount',
        'amount',
        'is_included',
        'currency',
        'sort',
    ];

    protected $casts = [
        'kind' => ChargeKind::class,
        'basis' => ChargeBasis::class,
        'rate' => 'float',
        'base_amount' => 'float',
        'amount' => 'float',
        'is_included' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The charge this came from, where it still exists. Never read for a
     * number — every number is on this row.
     *
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** How the line reads on an invoice: "VAT 15%" or "Park permit". */
    public function label(): string
    {
        return $this->basis->isPercentage()
            ? $this->name.' '.rtrim(rtrim(number_format($this->rate, 2), '0'), '.').'%'
            : $this->name;
    }
}

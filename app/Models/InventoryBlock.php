<?php

namespace App\Models;

use App\Enums\BlockReason;
use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units off sale without a guest. `first_night` and `last_night` are both
 * inclusive — see the migration for why a block counts nights while a
 * reservation counts an arrival and a departure.
 *
 * @property int $id
 * @property int $bookable_unit_id
 * @property BlockReason $reason
 * @property int $units
 * @property CarbonImmutable $first_night
 * @property CarbonImmutable $last_night
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $released_at
 * @property-read BookableUnit|null $bookableUnit
 */
class InventoryBlock extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'bookable_unit_id',
        'reason',
        'units',
        'first_night',
        'last_night',
        'note',
        'created_by',
        'released_at',
    ];

    protected $casts = [
        'reason' => BlockReason::class,
        'units' => 'integer',
        'first_night' => 'date',
        'last_night' => 'date',
        'released_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<BookableUnit, $this>
     */
    public function bookableUnit(): BelongsTo
    {
        return $this->belongsTo(BookableUnit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

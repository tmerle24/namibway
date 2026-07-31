<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listing_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string $source
 * @property bool $success
 * @property string|null $log
 * @property int|null $tokens_used
 * @property string|null $cost_estimate
 */
class EnrichmentJob extends Model
{
    protected $fillable = [
        'listing_id',
        'started_at',
        'finished_at',
        'source',
        'success',
        'log',
        'tokens_used',
        'cost_estimate',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'success' => 'boolean',
        'tokens_used' => 'integer',
        'cost_estimate' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}

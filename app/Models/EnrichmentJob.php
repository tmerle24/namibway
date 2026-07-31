<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $listing_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string $source
 * @property string|null $actor
 * @property bool $success
 * @property string|null $log
 * @property array<int, string>|null $fields_changed
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
        'actor',
        'success',
        'log',
        'fields_changed',
        'tokens_used',
        'cost_estimate',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'success' => 'boolean',
        'fields_changed' => 'array',
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

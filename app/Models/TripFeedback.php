<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $trip_id
 * @property string|null $name
 * @property int|null $rating
 * @property string $message
 */
class TripFeedback extends Model
{
    protected $fillable = ['trip_id', 'name', 'rating', 'message'];

    protected $casts = ['rating' => 'integer'];

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}

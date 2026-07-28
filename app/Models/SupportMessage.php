<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $trip_id
 * @property string $name
 * @property string $email
 * @property string $message
 */
class SupportMessage extends Model
{
    protected $fillable = ['trip_id', 'name', 'email', 'message'];

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}

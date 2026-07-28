<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $token
 * @property string|null $title
 * @property array<string, mixed> $plan_json
 * @property int|null $user_id
 * @property string|null $session_id
 */
class SavedPlan extends Model
{
    protected $fillable = [
        'token',
        'title',
        'plan_json',
        'user_id',
        'session_id',
    ];

    protected $casts = [
        'plan_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (SavedPlan $plan) {
            if (blank($plan->token)) {
                do {
                    $plan->token = Str::random(12);
                } while (self::where('token', $plan->token)->exists());
            }
        });
    }

    /** @return BelongsTo<User, self> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

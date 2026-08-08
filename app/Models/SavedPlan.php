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
 * @property int $version
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
        'version' => 'integer',
    ];

    // Mirrors the column default so a freshly created model already carries
    // version 1 in memory — the DB default alone leaves $saved->version null
    // until the row is re-read, which savePlan() would otherwise hand straight
    // back to the client as its starting version. Deliberately not $fillable:
    // the version is the server's to move, never the request's.
    protected $attributes = [
        'version' => 1,
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

    /** @phpstan-ignore missingType.generics */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

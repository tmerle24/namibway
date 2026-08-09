<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $saved_plan_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon $check_in
 * @property Carbon $check_out
 * @property int $adults
 * @property int $children
 * @property string $variant_name
 * @property array<mixed> $plan
 */
class Trip extends Model
{
    // `user_id`/`saved_plan_id` are fillable so the controller can set them at
    // create time, but they are server-resolved values only — never take either
    // one from request input, or "only the creator can book" becomes a field
    // the booker fills in themselves.
    protected $fillable = [
        'user_id',
        'saved_plan_id',
        'name',
        'email',
        'phone',
        'check_in',
        'check_out',
        'adults',
        'children',
        'variant_name',
        'plan',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'plan' => 'array',
    ];

    /**
     * @return HasMany<Inquiry, $this>
     */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /** @phpstan-ignore missingType.generics */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @phpstan-ignore missingType.generics */
    public function savedPlan(): BelongsTo
    {
        return $this->belongsTo(SavedPlan::class);
    }
}

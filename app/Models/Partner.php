<?php

namespace App\Models;

use App\Enums\ConnectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $bio
 * @property string|null $logo
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $instagram
 * @property string|null $facebook
 * @property string|null $source_url
 * @property string|null $claim_token
 * @property \Illuminate\Support\Carbon|null $claim_token_sent_at
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property ConnectorType|null $connector_type
 * @property string|null $connector_property_code
 * @property array<string, mixed>|null $connector_config
 */
class Partner extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['bio'];

    protected $fillable = [
        'user_id',
        'name',
        'bio',
        'logo',
        'email',
        'phone',
        'website',
        'instagram',
        'facebook',
        'source_url',
        'claim_token',
        'claim_token_sent_at',
        'claimed_at',
        'connector_type',
        'connector_property_code',
        'connector_config',
    ];

    protected $casts = [
        'connector_type' => ConnectorType::class,
        'connector_config' => 'encrypted:array',
    ];

    /**
     * @return BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}

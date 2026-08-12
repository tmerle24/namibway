<?php

namespace App\Models;

use App\Enums\ConnectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $claim_token_sent_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $claim_rejected_at
 * @property ConnectorType|null $connector_type
 * @property array<string, mixed>|null $connector_config
 * @property Carbon|null $connector_verified_at
 * @property bool $is_demo
 * @property bool $booking_enabled
 * @property bool $booking_demo_mode
 * @property string|null $booking_email
 * @property string|null $booking_demo_email
 * @property int|null $demo_source_listing_id
 * @property float|null $commission_rate
 * @property float|null $deposit_rate
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
        'claim_rejected_at',
        'connector_type',
        'connector_config',
        'connector_verified_at',
        'is_demo',
        'booking_enabled',
        'booking_demo_mode',
        'booking_email',
        'booking_demo_email',
        'demo_source_listing_id',
        'commission_rate',
        'deposit_rate',
    ];

    protected $casts = [
        'connector_type' => ConnectorType::class,
        'connector_config' => 'encrypted:array',
        'claim_token_sent_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_rejected_at' => 'datetime',
        'connector_verified_at' => 'datetime',
        'is_demo' => 'boolean',
        'booking_enabled' => 'boolean',
        'booking_demo_mode' => 'boolean',
        'commission_rate' => 'float',
        'deposit_rate' => 'float',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * The real listing a demo tenant was built from. Read-only: the demo path
     * copies out of it and never writes back, which is why the copy exists at
     * all rather than the demo partner simply being pointed at the real row.
     *
     * @return BelongsTo<Listing, $this>
     */
    public function demoSourceListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'demo_source_listing_id');
    }

    /**
     * @return HasMany<PartnerMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(PartnerMessage::class);
    }

    /**
     * Applies a connector type/credential change, whether it came from staff
     * (admin Filament panel — trusted immediately, since they've reviewed
     * what they typed) or from the owner themselves (partner portal or the
     * token-based /listings/{slug}/edit page — credentials go in, but stay
     * unverified until staff actually checks them). Doesn't save(); caller
     * does that once other attributes are set too.
     *
     * ConnectorFactory refuses to build a live connector until
     * connector_verified_at is set, so an owner-submitted typo can't reach
     * real guest bookings before someone here has looked at it.
     *
     * @param  array<string, mixed>  $config
     */
    public function setConnectorSetup(?string $type, array $config, bool $trustedSource): void
    {
        $changed = $this->connector_type?->value !== $type
            || ($this->connector_config ?? []) !== $config;

        $verifiedAt = match (true) {
            $trustedSource => $type !== null ? now() : null,
            $changed => null,
            default => $this->connector_verified_at,
        };

        // fill() rather than direct property assignment: connector_type/
        // connector_verified_at go through Eloquent's enum/datetime casts on
        // write this way, so callers can pass the plain scalar values
        // collapseConfigForSave()/collapseConnectorConfig() already produce.
        $this->fill([
            'connector_type' => $type,
            'connector_config' => $config,
            'connector_verified_at' => $verifiedAt,
        ]);
    }
}

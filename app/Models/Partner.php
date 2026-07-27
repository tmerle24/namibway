<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property string $name
 * @property string|null $bio
 * @property string|null $logo
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $instagram
 * @property string|null $facebook
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
    ];

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}

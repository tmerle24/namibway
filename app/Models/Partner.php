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
        'name',
        'bio',
        'logo',
        'email',
        'phone',
        'website',
        'instagram',
        'facebook',
    ];

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}

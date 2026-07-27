<?php

namespace App\Models;

use App\Enums\ConnectorType;
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
 * @property string|null $connector_type
 * @property string|null $connector_property_code
 * @property array<string, mixed>|null $connector_config
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
        'connector_type',
        'connector_property_code',
        'connector_config',
    ];

    protected $casts = [
        'connector_type' => ConnectorType::class,
        'connector_config' => 'encrypted:array',
    ];

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A partner/OTA consumer of the public listings API, and the Sanctum
 * "tokenable" owning any tokens issued to that consumer (see
 * app/Filament/Resources/ApiClientResource.php for token issuance/revocation).
 *
 * @property int $id
 * @property string $name
 * @property string|null $contact_email
 * @property bool $is_active
 */
class ApiClient extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

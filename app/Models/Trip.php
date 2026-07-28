<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
    protected $fillable = [
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
}

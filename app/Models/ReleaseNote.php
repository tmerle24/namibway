<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $version
 * @property Carbon $released_at
 * @property string $title
 * @property string|null $summary
 * @property string $body
 */
class ReleaseNote extends Model
{
    protected $fillable = [
        'version',
        'released_at',
        'title',
        'summary',
        'body',
    ];

    protected $casts = [
        'released_at' => 'date',
    ];
}

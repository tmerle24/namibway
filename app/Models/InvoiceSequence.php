<?php

namespace App\Models;

use App\Models\Concerns\GuardsMoneyWrites;
use Illuminate\Database\Eloquent\Model;

/**
 * The counter behind gapless numbering. See the migration for why this is a
 * locked row rather than `max(number) + 1` or an auto-increment.
 *
 * Read and written only by App\Services\Payments\InvoiceIssuer, and there
 * through the query builder with `lockForUpdate()` — this model exists so the
 * table has a name in PHP and so the write guard covers it, not because
 * anything loads it as an Eloquent record in anger.
 *
 * @property int $id
 * @property string $series
 * @property int $year
 * @property int $next_number
 */
class InvoiceSequence extends Model
{
    use GuardsMoneyWrites;

    protected $fillable = [
        'series',
        'year',
        'next_number',
    ];

    protected $casts = [
        'year' => 'integer',
        'next_number' => 'integer',
    ];
}

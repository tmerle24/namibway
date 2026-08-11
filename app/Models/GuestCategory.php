<?php

namespace App\Models;

use App\Enums\GuestKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kind of guest a property charges differently — see the migration for why
 * the age bands are data rather than an enum.
 *
 * Configuration, not inventory: a lodge edits these in a form, so this is not
 * guarded by GuardsInventoryWrites. The money that comes out of them is, and
 * it is frozen onto reservation_nights like every other price.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $code
 * @property string $name
 * @property GuestKind $kind
 * @property int|null $min_age
 * @property int|null $max_age
 * @property float $charge_percent
 * @property bool $counts_toward_occupancy
 * @property int $sort
 * @property bool $is_active
 * @property-read Listing|null $listing
 */
class GuestCategory extends Model
{
    protected $fillable = [
        'listing_id',
        'code',
        'name',
        'kind',
        'min_age',
        'max_age',
        'charge_percent',
        'counts_toward_occupancy',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'kind' => GuestKind::class,
        'min_age' => 'integer',
        'max_age' => 'integer',
        'charge_percent' => 'float',
        'counts_toward_occupancy' => 'boolean',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The categories a property charges by, in the order a rate sheet lists
     * them: adults first, then down the ages.
     *
     * @return Collection<int, GuestCategory>
     */
    public static function forListing(Listing $listing): Collection
    {
        return self::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** "Child (3–11), 50%" — the whole rule in one line, for a select. */
    public function label(): string
    {
        $ages = $this->ageLabel();
        $label = $ages === null ? $this->name : $this->name.' ('.$ages.')';

        return $this->charge_percent >= 100.0
            ? $label
            : $label.' — '.$this->percentLabel();
    }

    public function ageLabel(): ?string
    {
        if ($this->min_age === null && $this->max_age === null) {
            return null;
        }

        if ($this->max_age === null) {
            return $this->min_age.'+';
        }

        if ($this->min_age === null || $this->min_age === 0) {
            return 'under '.($this->max_age + 1);
        }

        return $this->min_age.'–'.$this->max_age;
    }

    public function percentLabel(): string
    {
        if ($this->charge_percent <= 0.0) {
            return 'free';
        }

        return rtrim(rtrim(number_format($this->charge_percent, 2, '.', ''), '0'), '.').'%';
    }
}

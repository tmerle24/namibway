<?php

namespace App\Models;

use App\Enums\AmenityCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing a room can have, from the catalogue everyone shares — see the
 * migration for why it is shared rather than per property.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AmenityCategory $category
 * @property int $sort
 * @property bool $is_active
 * @property-read Collection<int, RoomType> $roomTypes
 */
class Amenity extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'category' => AmenityCategory::class,
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class)->withTimestamps();
    }

    /**
     * The catalogue in the order a room description reads: bathroom together,
     * power together, and never alphabetical across the lot.
     *
     * @return Collection<int, Amenity>
     */
    public static function catalogue(): Collection
    {
        // Sorted on one composed key rather than in the database: the category
        // order is the enum's business (a reading order, not alphabetical),
        // and it would otherwise have to be duplicated as a CASE expression
        // that nobody would remember to keep in step.
        return self::query()
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Amenity $amenity): string => sprintf(
                '%03d-%05d-%s',
                $amenity->category->sort(),
                $amenity->sort,
                $amenity->name,
            ))
            ->values();
    }

    /** "Bathroom · Outdoor shower" — enough to pick from a long list. */
    public function label(): string
    {
        return $this->category->label().' · '.$this->name;
    }
}

<?php

namespace App\Models;

use Database\Factories\DocumentCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A folder in the team's document store.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property int $position
 * @property-read Collection<int, Document> $documents
 * @property-read int|null $documents_count
 */
class DocumentCategory extends Model
{
    /** @use HasFactory<DocumentCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        // The slug is what a link to a folder is made of, so it is filled in
        // rather than demanded — nobody filing a contract should have to think
        // about URL segments.
        static::saving(function (self $category): void {
            if (blank($category->slug)) {
                $category->slug = static::uniqueSlug($category->name, $category->id);
            }
        });
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    private static function uniqueSlug(string $name, ?int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'folder';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}

<?php

namespace App\Models;

use Database\Factories\DocumentCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A folder in the team's document store — a real folder, with folders inside it.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property-read DocumentCategory|null $parent
 * @property-read Collection<int, DocumentCategory> $children
 * @property-read Collection<int, Document> $documents
 * @property-read int|null $documents_count
 * @property-read int|null $children_count
 */
class DocumentCategory extends Model
{
    /** @use HasFactory<DocumentCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
    ];

    /**
     * A short list of heroicons that actually mean something for this kind of
     * filing. A free-text icon field is a way to end up with a broken icon on
     * the one screen everybody uses.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'heroicon-o-folder' => 'Folder',
            'heroicon-o-megaphone' => 'Marketing',
            'heroicon-o-briefcase' => 'Business',
            'heroicon-o-photo' => 'Brand & logos',
            'heroicon-o-scale' => 'Legal',
            'heroicon-o-banknotes' => 'Finance',
            'heroicon-o-user-group' => 'Team',
            'heroicon-o-map' => 'Product',
            'heroicon-o-wrench-screwdriver' => 'Operations',
        ];
    }

    protected static function booted(): void
    {
        // The slug is what a link to a folder is made of, so it is filled in
        // rather than demanded — nobody filing a contract should have to think
        // about URL segments.
        static::saving(function (self $category): void {
            if (blank($category->slug)) {
                $category->slug = self::uniqueSlug($category->name, $category->id);
            }
        });
    }

    /**
     * @return BelongsTo<DocumentCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<DocumentCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Whether anything at all is filed in here. A folder that is empty may go. */
    public function isEmpty(): bool
    {
        return ! $this->children()->exists() && ! $this->documents()->exists();
    }

    /**
     * The path from the top down to and including this folder — what the
     * breadcrumb reads.
     *
     * Walked one parent at a time rather than in a recursive CTE: a folder tree
     * people maintain by hand is a handful of levels deep, and the guard below
     * means the loop cannot run away even if a cycle somehow got written.
     *
     * @return list<DocumentCategory>
     */
    public function path(): array
    {
        $path = [$this];
        $folder = $this;
        $guard = 0;

        while (($folder = $folder->parent) !== null && $guard++ < 50) {
            array_unshift($path, $folder);
        }

        return $path;
    }

    /**
     * This folder and everything under it, by id — the set a folder may not be
     * moved into, because a folder inside itself is a subtree nobody can reach.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];
        $guard = 0;

        while ($level !== [] && $guard++ < 50) {
            /** @var list<int> $level */
            $level = static::query()
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->all();

            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Every folder as "Parent / Child", for a select that has to name a place in
     * the tree rather than just a folder.
     *
     * @param  list<int>  $except  folders to leave out (a move excludes its own subtree)
     * @return array<int, string>
     */
    public static function options(array $except = []): array
    {
        // One query, then the paths are assembled in memory — a select that
        // costs a query per folder is how a tree screen gets slow at exactly
        // the size where the tree starts being useful.
        $folders = static::query()->orderBy('name')->get()->keyBy('id');

        $labels = [];

        foreach ($folders as $id => $folder) {
            if (in_array($id, $except, strict: true)) {
                continue;
            }

            $names = [$folder->name];
            $step = $folder;
            $guard = 0;

            while (($step = $folders->get($step->parent_id)) !== null && $guard++ < 50) {
                array_unshift($names, $step->name);
            }

            $labels[(int) $id] = implode(' / ', $names);
        }

        asort($labels);

        return $labels;
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

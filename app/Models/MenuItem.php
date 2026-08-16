<?php

namespace App\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One line of a restaurant's menu — a dish, a drink, a set menu.
 *
 * Ordering from these is the second thing a restaurant listing can be asked
 * for; the first is a table (see `App\Enums\InquiryKind`). A restaurant with an
 * empty menu is a normal state and the only thing it costs is the order tab:
 * the page then offers a table and nothing else.
 *
 * Plain strings rather than translatable columns, the same as `BookableUnit`
 * and unlike `Listing`. A menu is the owner's own wording — "Oryx carpaccio" is
 * not translated into five languages by anybody, and a translatable column that
 * only ever holds English is a promise the panel would have to keep.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property float $price
 * @property string $currency
 * @property string|null $image
 * @property bool $is_available
 * @property int $sort
 */
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    /** Where a dish with no section of its own is shown. */
    public const UNCATEGORISED = 'Menu';

    protected $fillable = [
        'listing_id',
        'name',
        'description',
        'category',
        'price',
        'currency',
        'image',
        'is_available',
        'sort',
    ];

    protected $casts = [
        'price' => 'float',
        'is_available' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * One restaurant sells in one currency.
     *
     * The same guard `BookableUnit` carries, for the same reason and with the
     * same message: an order adds its lines together, and two currencies in one
     * total is a number that means nothing. Caught here rather than at order
     * time, because by then the menu is entered and somebody has been quoting
     * it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if (blank($item->currency) || (! $item->isDirty('currency') && $item->exists)) {
                return;
            }

            $sibling = self::query()
                ->where('listing_id', $item->listing_id)
                ->when($item->exists, fn (Builder $query) => $query->whereKeyNot($item->getKey()))
                ->whereNotNull('currency')
                ->where('currency', '!=', $item->currency)
                ->first();

            if ($sibling !== null) {
                throw new InvalidArgumentException(
                    "This menu is priced in {$sibling->currency} ({$sibling->name}), "
                    ."so {$item->name} cannot be in {$item->currency}. "
                    .'An order adds its lines together, so one menu is one currency — '
                    .'change this item or change the rest of the menu.'
                );
            }
        });
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @param  Builder<MenuItem>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }

    /** What this item is filed under on the menu. */
    public function section(): string
    {
        return filled($this->category) ? $this->category : self::UNCATEGORISED;
    }

    /**
     * The menu grouped into its sections, in reading order.
     *
     * Sections come out in the order their first item does rather than
     * alphabetically: a menu is written starters-first, and sorting it would
     * put "Desserts" above "Mains" on every restaurant in the country.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, array{category: string, items: array<int, MenuItem>}>
     */
    public static function intoSections(Collection $items): array
    {
        $sections = [];

        foreach ($items as $item) {
            $sections[$item->section()][] = $item;
        }

        return array_map(
            fn (string $category, array $items): array => ['category' => $category, 'items' => $items],
            array_keys($sections),
            array_values($sections),
        );
    }
}

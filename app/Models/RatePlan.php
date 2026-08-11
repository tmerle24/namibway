<?php

namespace App\Models;

use App\Enums\BoardBasis;
use App\Enums\PricingStrategy;
use App\Enums\RatePlanEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product a property sells — see BOOKING_SYSTEM.md.
 *
 * Not guarded by GuardsInventoryWrites, unlike RatePlanDay: a rate plan is
 * configuration a lodge edits in a form, while the nights under it are
 * inventory and go through InventoryWriter like everything else.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property BoardBasis|null $board_basis
 * @property RatePlanEligibility $eligibility
 * @property PricingStrategy $pricing_strategy
 * @property int|null $cancellation_days
 * @property bool $is_refundable
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property-read Listing|null $listing
 * @property-read Collection<int, RatePlanDay> $days
 */
class RatePlan extends Model
{
    protected $fillable = [
        'listing_id',
        'name',
        'code',
        'description',
        'board_basis',
        'eligibility',
        'pricing_strategy',
        'cancellation_days',
        'is_refundable',
        'is_default',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'board_basis' => BoardBasis::class,
        'eligibility' => RatePlanEligibility::class,
        'pricing_strategy' => PricingStrategy::class,
        'cancellation_days' => 'integer',
        'is_refundable' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return HasMany<RatePlanDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(RatePlanDay::class);
    }

    /**
     * The plan a screen or a booking uses when nobody has chosen one.
     *
     * Returns null rather than creating anything: a property with no rate plan
     * is a valid state, and every reader falls back to the room type's own
     * rate for it. Reading must not write.
     */
    public static function defaultFor(Listing $listing): ?self
    {
        return self::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }

    /**
     * The plan a *write* uses — setting a rate needs somewhere to put it, so
     * this one does create the default if the property has none.
     *
     * Kept apart from defaultFor() on purpose. The two differ only in whether
     * they may write, and that is exactly the distinction worth having a
     * second method name for.
     */
    public static function ensureDefaultFor(Listing $listing): self
    {
        $existing = self::defaultFor($listing);

        if ($existing !== null) {
            return $existing;
        }

        return self::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            // Left unstated rather than guessed — see the migration.
            'board_basis' => null,
            'eligibility' => RatePlanEligibility::Public,
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Every plan a property offers, in the order a rate sheet lists them.
     *
     * @return Collection<int, RatePlan>
     */
    public static function forListing(Listing $listing): Collection
    {
        return self::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** What to put on a switcher: the name, and what it includes if it says. */
    public function label(): string
    {
        $board = $this->board_basis?->shortLabel();

        return $board === null ? $this->name : $this->name.' ('.$board.')';
    }
}

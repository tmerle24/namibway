<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bookable item in a saved trip plan.
 *
 * Created when a booking request is sent — JSON stays for planning, rows
 * arrive when a plan item becomes bookable (BOOKING_BEYOND_ROOMS.md § 7.5).
 *
 * The `inquiry_id` is set at the same moment the Inquiry is created. It goes
 * null if the inquiry is ever deleted (rare — inquiries die by status, not by
 * hard delete), leaving the item as the plan's record that something was once
 * booked here. The UNIQUE constraint on `inquiry_id` means one booking per
 * item; a second attempt at the same accommodation produces a new item row.
 *
 * @property int $id
 * @property int $saved_plan_id
 * @property int|null $listing_id
 * @property ListingType $kind
 * @property Carbon|null $date
 * @property Carbon|null $date_to
 * @property int|null $inquiry_id
 * @property int $sort
 * @property-read SavedPlan $plan
 * @property-read Listing|null $listing
 * @property-read Inquiry|null $inquiry
 */
class ItineraryItem extends Model
{
    protected $fillable = [
        'saved_plan_id',
        'listing_id',
        'kind',
        'date',
        'date_to',
        'inquiry_id',
        'sort',
    ];

    protected $casts = [
        'kind' => ListingType::class,
        'date' => 'date',
        'date_to' => 'date',
    ];

    /** @return BelongsTo<SavedPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SavedPlan::class, 'saved_plan_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<Inquiry, $this> */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /**
     * True once the partner has confirmed this booking.
     *
     * Derived from the inquiry rather than stored so it can never disagree
     * with the inquiry's own status.
     */
    public function isConfirmed(): bool
    {
        return $this->inquiry_id !== null
            && $this->inquiry?->status === InquiryStatus::Confirmed;
    }
}

<?php

namespace App\Models;

use App\Enums\InquiryKind;
use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $listing_id
 * @property int|null $partner_id
 * @property int|null $trip_id
 * @property int|null $user_id
 * @property InquiryKind $kind
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $travel_dates
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property string|null $arrival_time
 * @property int $adults
 * @property int $children
 * @property string|null $message
 * @property InquiryStatus $status
 * @property string|null $connector_reference
 * @property string|null $bookable_unit_code
 * @property float|null $total_amount
 * @property string|null $currency
 * @property Carbon|null $hold_expires_at
 * @property string|null $notes
 * @property-read Collection<int, InquiryItem> $items
 * @property-read Listing|null $listing
 * @property-read Partner|null $partner
 */
class Inquiry extends Model
{
    // `user_id` is the account that sent the request — server-resolved from the
    // session, never from request input (see Trip's note on the same field).
    protected $fillable = [
        'listing_id',
        'partner_id',
        'trip_id',
        'user_id',
        'kind',
        'name',
        'email',
        'phone',
        'travel_dates',
        'check_in',
        'check_out',
        'arrival_time',
        'adults',
        'children',
        'message',
        'status',
        'connector_reference',
        'bookable_unit_code',
        'total_amount',
        'currency',
        'payment_state',
        'hold_expires_at',
        'notes',
    ];

    /**
     * A request is a booking request unless it says otherwise.
     *
     * The column carries the same default, but that one only applies once the
     * row reaches the database — and the observer, the promoter and the emails
     * all read `kind` off the *model*, including the instance handed straight
     * to `created()`. Without this, every caller that does not name a kind
     * (the shortlist batch, a plan's bookings) would hand those readers a null
     * and fatal on the first `$inquiry->kind->…`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => InquiryKind::Booking->value,
    ];

    protected $casts = [
        'kind' => InquiryKind::class,
        'status' => InquiryStatus::class,
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'total_amount' => 'float',
        'trip_id' => 'integer',
        'hold_expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The business being asked, where it is named directly rather than through
     * a listing. Filled on every row, including listing-backed ones.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Who this request is for.
     *
     * A listing where there is one, the partner otherwise — a shop that never
     * listed on the travel platform is asked for goods through its own website
     * and has no listing to hang the request on. The database refuses a row
     * that names neither, so this only returns null for an unsaved model.
     */
    public function seller(): Listing|Partner|null
    {
        return $this->listing ?? $this->partner;
    }

    /**
     * What to call the business in a subject line, a table cell or a heading.
     *
     * One reader instead of `$inquiry->listing->name` in a dozen places, which
     * is what used to fatal the moment a request had no listing.
     */
    public function sellerName(): string
    {
        $seller = $this->seller();

        return $seller === null ? 'the business' : $seller->name;
    }

    /**
     * Where a request to this business is answered.
     *
     * The listing's own contact address is tried first — it is the most
     * specific reach for that property. The inquiry's direct partner (set on
     * shop orders and site enquiries) and then the listing's owning partner
     * are fallbacks for when no per-listing address is recorded.
     */
    public function sellerEmail(): ?string
    {
        return $this->listing?->contact_email
            ?: $this->partner?->email
            ?: $this->listing?->partner?->email;
    }

    /**
     * The lines of an order. Empty for every other kind of request.
     *
     * @return HasMany<InquiryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InquiryItem::class);
    }

    /**
     * The requested time as a person writes it — "19:30", not "19:30:00".
     *
     * Postgres hands back a `time` column with its seconds, which no restaurant
     * asked for and which reads as machine output in an email.
     */
    public function arrivalTimeLabel(): ?string
    {
        return filled($this->arrival_time) ? substr($this->arrival_time, 0, 5) : null;
    }

    /**
     * The plan item this request was made from, where the request came through
     * the trip plan rather than a direct listing page.
     *
     * @return HasOne<ItineraryItem, $this>
     */
    public function itineraryItem(): HasOne
    {
        return $this->hasOne(ItineraryItem::class);
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stay this request holds on the property's calendar, where it took
     * one — provisional while the partner decides, confirmed once they say
     * yes. Unique on the reservation side, so there is never more than one.
     *
     * @return HasOne<Reservation, $this>
     */
    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }
}

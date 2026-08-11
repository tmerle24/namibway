<?php

namespace App\Models;

use App\Services\Region\PhoneNumberFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Somebody a property sells to. See the migration for why the word is
 * "customer", why the record is scoped to the partner, and why recognition
 * goes by account first and email second.
 *
 * Write through App\Services\Booking\CustomerDirectory rather than here: it
 * owns the normalisation the two unique keys depend on, and it is where the
 * rule lives that an existing customer is **used, not overwritten** — a typo
 * in one booking must not rewrite a record built over three seasons.
 *
 * Nothing on this model is a stored count. "How many stays" and "when were
 * they last here" are questions about reservations, and a counter kept beside
 * them is a counter that will one day disagree with them.
 *
 * @property int $id
 * @property int $partner_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $phone_normalised
 * @property string|null $country
 * @property string|null $language
 * @property CarbonImmutable|null $created_at
 * @property-read Partner|null $partner
 * @property-read User|null $user
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Note> $notes
 */
class Customer extends Model
{
    protected $fillable = [
        'partner_id',
        'user_id',
        'name',
        'email',
        'phone',
        'country',
        'language',
    ];

    /**
     * The two normalised forms follow their originals, wherever the write came
     * from — the directory, an admin correcting a typo, a future import.
     *
     * On the model rather than in each writer because both are load-bearing:
     * the email is what a case-insensitive unique key rests on, and the phone
     * is what "the same number" means when a guest reads out 081 234 5678 and
     * the desk stored +264 81 234 5678.
     */
    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            $email = trim((string) $customer->email);
            $customer->email = $email === '' ? null : mb_strtolower($email);

            if ($customer->isDirty('phone')) {
                $phone = trim((string) $customer->phone);
                $customer->phone = $phone === '' ? null : $phone;
                $customer->phone_normalised = app(PhoneNumberFormatter::class)->toInternational($phone);
            }
        });
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * The NamibWay account, where this person has one. This is the link that
     * survives an email change, which is why it is matched on first.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest();
    }

    /** Whether this person has ever finished a stay here. */
    public function isReturning(): bool
    {
        return $this->reservations()->count() > 1;
    }
}

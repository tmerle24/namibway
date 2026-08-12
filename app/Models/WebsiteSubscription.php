<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A partner's subscription to the website product.
 *
 * The transitions live here rather than in whatever screen calls them, because
 * there are three screens already — the owner's order button, our admin, and
 * whatever bills them later — and a state machine spread across three callers
 * is three state machines.
 *
 * Nothing here knows a price or a payment provider. See the migration.
 *
 * @property int $id
 * @property int $partner_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $requested_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $cancelled_at
 * @property string|null $note
 * @property-read Partner|null $partner
 */
class WebsiteSubscription extends Model
{
    protected $fillable = [
        'partner_id',
        'status',
        'requested_at',
        'activated_at',
        'suspended_at',
        'cancelled_at',
        'note',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'requested_at' => 'datetime',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isEntitled(): bool
    {
        return $this->status->isEntitled();
    }

    /**
     * The owner asking for a website.
     *
     * Idempotent on purpose: the button is in a panel somebody can press twice,
     * and a second request is not a second customer. An existing subscription
     * in any state is returned as it is — a cancelled customer coming back is a
     * conversation, not a row.
     */
    public static function request(Partner $partner, ?string $note = null): self
    {
        $existing = self::query()->where('partner_id', $partner->id)->first();

        if ($existing instanceof self) {
            return $existing;
        }

        return self::create([
            'partner_id' => $partner->id,
            'status' => SubscriptionStatus::Requested,
            'requested_at' => now(),
            'note' => $note,
        ]);
    }

    /**
     * They are a customer now.
     *
     * `activated_at` is only written the first time: a subscription that was
     * suspended and switched back on is the same customer, and the date they
     * became one does not move.
     */
    public function activate(): void
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Active,
            'activated_at' => $this->activated_at ?? now(),
            'suspended_at' => null,
        ])->save();
    }

    public function suspend(?string $note = null): void
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Suspended,
            'suspended_at' => now(),
            'note' => $note ?: $this->note,
        ])->save();
    }

    public function cancel(?string $note = null): void
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'note' => $note ?: $this->note,
        ])->save();
    }
}

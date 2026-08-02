<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $partner_id
 * @property int|null $listing_id
 * @property int|null $sent_by
 * @property string $direction
 * @property string|null $template
 * @property string|null $source_uid
 * @property string $subject
 * @property string $body
 * @property Carbon|null $sent_at
 * @property Carbon|null $read_at
 */
class PartnerMessage extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'partner_id',
        'listing_id',
        'sent_by',
        'direction',
        'template',
        'source_uid',
        'subject',
        'body',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @param  Builder<PartnerMessage>  $query
     * @return Builder<PartnerMessage>
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * @param  Builder<PartnerMessage>  $query
     * @return Builder<PartnerMessage>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}

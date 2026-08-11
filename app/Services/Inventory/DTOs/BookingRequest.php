<?php

namespace App\Services\Inventory\DTOs;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\Listing;

/**
 * Everything InventoryWriter::book() needs. A walk-in and a website booking
 * differ only in `source` and whether `inquiryId` is set — the inventory
 * mechanics are identical, which is the point of having one write path.
 */
class BookingRequest
{
    /**
     * @param  array<int, BookingLine>  $lines
     * @param  float|null  $totalOverride  What the lodge decided to charge, when that
     *                                     is not what the calendar priced. The calendar's
     *                                     own figure is kept either way — see the
     *                                     reservations price-override migration.
     * @param  string|null  $promotionCode  A code somebody typed in. Null still lets a
     *                                       public offer apply by itself; a code that does
     *                                       not work refuses the booking rather than
     *                                       quietly charging full price.
     * @param  bool  $notify  Whether this booking should tell anybody about itself.
     *                        True for every real one. False exists for seeding a demo
     *                        property with months of history, which would otherwise
     *                        post hundreds of confirmations about guests who do not
     *                        exist.
     * @param  bool  $ignoreStayRules  Record the stay without checking minimum stays and
     *                                 closed-to-arrival days. Those are rules about
     *                                 *selling* a night; a stay already confirmed to a
     *                                 guest is being written down, not sold again, and
     *                                 refusing it would leave the calendar lying about how
     *                                 full the property is. Availability is still enforced
     *                                 — see StayPromoter, the only caller.
     * @param  int|null  $guestUserId  The NamibWay account this stay belongs to, where
     *                                 there is one — not the same thing as `createdBy`,
     *                                 which is the staff member typing it in. It is what
     *                                 CustomerDirectory matches on first, because an
     *                                 account survives an email change.
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly array $lines,
        public readonly string $guestName,
        public readonly ?string $guestEmail = null,
        public readonly ?string $guestPhone = null,
        public readonly ReservationSource $source = ReservationSource::PartnerEntered,
        public readonly StayStatus $status = StayStatus::Confirmed,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly ?string $notes = null,
        public readonly ?int $createdBy = null,
        public readonly ?int $inquiryId = null,
        public readonly ?float $totalOverride = null,
        public readonly ?string $overrideReason = null,
        public readonly ?string $promotionCode = null,
        public readonly bool $notify = true,
        public readonly ?int $guestUserId = null,
        public readonly bool $ignoreStayRules = false,
        /**
         * What the desk said when it put more people in a room than it
         * sleeps. Kept because "which rooms need an extra bed tonight" is a
         * question with an answer — see the migration. Null on every booking
         * that fits, which is nearly all of them.
         */
        public readonly ?string $overCapacityNote = null,
    ) {}
}

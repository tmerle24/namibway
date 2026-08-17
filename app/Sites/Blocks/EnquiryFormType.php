<?php

namespace App\Sites\Blocks;

use App\Enums\InquiryKind;

/**
 * What the one contact form on a customer's website asks for.
 *
 * **Exactly one per site.** A page that offers a table booking *and* a product
 * order *and* a general contact form is a page that has not decided what it
 * sells, and every extra choice costs the visitor the one they wanted. So this
 * is a single select, not a set of toggles.
 *
 * It replaces the old `mode` (`stay` / `visit`), which only ever distinguished
 * two of these five and was set from the business type at generation.
 *
 * ## The relationship to InquiryKind
 *
 * These are not the same thing and must not be merged. `InquiryKind` is what
 * the *stored request* is — three shapes the whole platform understands, shared
 * with namibway.com. This is what the *form on one website* offers, which is a
 * presentation choice the owner makes. Several form types produce the same kind
 * (a contact message and a stay request are both `booking`), and one form type
 * produces no request record at all (WhatsApp leaves the site entirely).
 */
enum EnquiryFormType: string
{
    /** Name, email, message. No dates, nothing to sell — just reach us. */
    case Contact = 'contact';

    /** A stay: arrival and departure, adults and children. */
    case StayRequest = 'stay_request';

    /** A table: one date, one time, a number of people. */
    case TableReservation = 'table_reservation';

    /** Food from the property's menu, with quantities. */
    case RestaurantOrder = 'restaurant_order';

    /** Goods from the site's own shop, with quantities and an address. */
    case ProductOrder = 'product_order';

    /** An enquiry about a single product — name and email only, no cart or address. */
    case ProductEnquiry = 'product_enquiry';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Contact — name, email, message',
            self::StayRequest => 'Reservation request — dates and guests',
            self::TableReservation => 'Table reservation — date, time and party',
            self::RestaurantOrder => 'Restaurant order — from the menu',
            self::ProductOrder => 'Product order — from the shop',
            self::ProductEnquiry => 'Product enquiry — about a single item',
        };
    }

    /**
     * The heading above the form, when the owner has not written one.
     */
    public function heading(): string
    {
        return match ($this) {
            self::Contact => 'Get in touch',
            self::StayRequest => 'Request availability',
            self::TableReservation => 'Book a table',
            self::RestaurantOrder => 'Order online',
            self::ProductOrder => 'Buy online',
            self::ProductEnquiry => 'Send an enquiry',
        };
    }

    /**
     * The menu button, when the owner has not written one.
     *
     * Short on purpose: this lands in a 96px-wide button on a phone, which is
     * why the heading above the form and the button pointing at it are two
     * different strings. `SiteActions` used to derive one from the other and
     * cap it at 14 characters, which is how "Request availability" became
     * "Enquire" on every site whether or not that was the right word.
     */
    public function buttonLabel(): string
    {
        return match ($this) {
            self::Contact => 'Contact',
            self::StayRequest => 'Enquire',
            self::TableReservation => 'Book a table',
            self::RestaurantOrder => 'Order',
            self::ProductOrder => 'Buy online',
            self::ProductEnquiry => 'Enquire',
        };
    }

    /**
     * The kind of request this form produces.
     *
     * A plain contact message is a `booking` with nothing filled in, which is
     * what it has always been — the platform's default shape, and the one the
     * partner mail already knows how to render.
     */
    public function inquiryKind(): InquiryKind
    {
        return match ($this) {
            self::Contact, self::StayRequest => InquiryKind::Booking,
            self::TableReservation => InquiryKind::TableReservation,
            self::RestaurantOrder, self::ProductOrder, self::ProductEnquiry => InquiryKind::Order,
        };
    }

    /** Whether this form sells something the visitor picks quantities of. */
    public function hasItems(): bool
    {
        return $this === self::RestaurantOrder || $this === self::ProductOrder;
    }

    /** Whether this form needs a delivery address. */
    public function needsAddress(): bool
    {
        return $this === self::ProductOrder;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

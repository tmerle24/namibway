<?php

namespace App\Sites\Blocks;

use App\Enums\ListingType;
use App\Models\Site;

/**
 * The contact form — the point of the whole page.
 *
 * It creates an `Inquiry` against the listing or the partner behind the site,
 * which is the same record the travel platform's own form creates and therefore
 * arrives in the same place: the business gets the mail with the signed confirm
 * and decline links, and the visitor gets an answer either way. None of that is
 * new here; this only gives it a second front door.
 *
 * ## One form, chosen by the owner
 *
 * `type` says which of the five this site shows — see `EnquiryFormType`. It is
 * a single select on purpose: a page offering a table booking *and* a product
 * order *and* a general contact form has not decided what it sells, and every
 * extra choice costs the visitor the one they came for.
 *
 * It replaces `mode` (`stay` / `visit`), which distinguished only two of the
 * five and was set from the business type at generation. Existing blocks are
 * migrated — `stay` became a reservation request, `visit` a table booking.
 *
 * ## Email or WhatsApp, never both
 *
 * `channel` decides how the form is answered. The block used to render the form
 * *and* a WhatsApp button underneath it, which asks the visitor to choose a
 * medium before they have said anything — and splits the business's own replies
 * across two inboxes for no gain.
 */
class EnquiryBlock extends BlockDefinition
{
    /** The form posts to us, and the business answers by email. */
    public const CHANNEL_EMAIL = 'email';

    /** No form at all — the fields are collected into a WhatsApp message. */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public function type(): string
    {
        return 'enquiry';
    }

    public function label(): string
    {
        return 'Contact form';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:300'],
            'form_type' => ['nullable', 'string', 'in:'.implode(',', array_column(EnquiryFormType::cases(), 'value'))],
            'channel' => ['nullable', 'string', 'in:'.self::CHANNEL_EMAIL.','.self::CHANNEL_WHATSAPP],
            'button_label' => ['nullable', 'string', 'max:24'],
        ];
    }

    public function isFilled(array $data): bool
    {
        // Whether this is worth rendering is a question about the business
        // behind the site, not about this payload — a form with nowhere to go
        // is worse than no form. The renderer answers it.
        return true;
    }

    /**
     * The form type this block is set to, falling back to a reservation
     * request — what every site generated before this field existed showed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function formType(array $data): EnquiryFormType
    {
        $value = $data['form_type'] ?? null;

        return (is_string($value) ? EnquiryFormType::tryFrom($value) : null)
            ?? EnquiryFormType::StayRequest;
    }

    /**
     * The form this site actually shows — the stored choice, corrected where the
     * business has since said it does not take that.
     *
     * A restaurant says which of the two channels it sells online
     * (`accepts_table_reservations`, `accepts_orders`, both on the listing and
     * shown in both panels). The block's own `form_type` was set when the site
     * was generated, from the business type and nothing else, and the migration
     * off `mode` could do no better — so a restaurant that has since switched to
     * ordering was still offering a table, and a form the platform would refuse
     * is a promise the page cannot keep.
     *
     * Only the two restaurant types are corrected, and only towards something
     * the listing allows. A `contact`, a stay request or a product order is the
     * owner saying what their page is for, and none of those is a claim about
     * this listing's booking channels.
     *
     * Deliberately **not** gated on `accepts_inquiries`: that switch is about
     * namibway.com, and a business's own website is a second front door that
     * stays open — see SiteEnquiryController.
     *
     * @param  array<string, mixed>  $data
     */
    public static function formTypeFor(Site $site, array $data): EnquiryFormType
    {
        $stored = self::formType($data);

        if (! in_array($stored, [EnquiryFormType::TableReservation, EnquiryFormType::RestaurantOrder], true)) {
            return $stored;
        }

        $listing = $site->sourceListing;

        // No listing means no menu and no table to speak for, so an order form
        // has nothing to offer and the honest fallback is a contact form.
        if ($listing === null || $listing->type !== ListingType::Restaurant) {
            return $stored === EnquiryFormType::RestaurantOrder ? EnquiryFormType::Contact : $stored;
        }

        // A switch over an empty menu is the same broken promise as a switch
        // that is off — the same rule Listing::requestKinds() applies.
        $takesOrders = $listing->accepts_orders && $listing->menuItems()->available()->exists();
        $takesTables = (bool) $listing->accepts_table_reservations;

        return match (true) {
            $stored === EnquiryFormType::TableReservation && $takesTables => $stored,
            $stored === EnquiryFormType::RestaurantOrder && $takesOrders => $stored,
            $takesTables => EnquiryFormType::TableReservation,
            $takesOrders => EnquiryFormType::RestaurantOrder,
            default => EnquiryFormType::Contact,
        };
    }

    /**
     * The heading above the form, and the same string in the menu.
     *
     * An owner who wrote their own keeps it. A heading that is only one of the
     * generated defaults follows the resolved type instead of being left behind
     * — otherwise a restaurant switched to ordering keeps a band headed "Book a
     * table" over a form asking for food.
     *
     * @param  array<string, mixed>  $data
     */
    public static function heading(EnquiryFormType $type, array $data): string
    {
        $heading = trim((string) ($data['heading'] ?? ''));

        foreach (EnquiryFormType::cases() as $case) {
            if ($heading === $case->heading()) {
                return $type->heading();
            }
        }

        return $heading !== '' ? $heading : $type->heading();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'heading' => EnquiryFormType::StayRequest->heading(),
            'intro' => null,
            'form_type' => EnquiryFormType::StayRequest->value,
            'channel' => self::CHANNEL_EMAIL,
            'button_label' => null,
        ];
    }
}

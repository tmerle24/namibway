<?php

namespace App\Sites\Blocks;

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

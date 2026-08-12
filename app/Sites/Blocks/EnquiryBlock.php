<?php

namespace App\Sites\Blocks;

/**
 * The enquiry form — the point of the whole page.
 *
 * It creates an `Inquiry` against the listing behind the site, which is the
 * same record the travel platform's own form creates and therefore arrives in
 * the same place: the partner gets the mail with the signed confirm and decline
 * links, and the guest gets a confirmation or a refusal depending on which one
 * is pressed. None of that is new here; this only gives it a second front door.
 *
 * ## What it asks for depends on what is being sold
 *
 * A lodge needs an arrival and a departure. An activity or a restaurant needs a
 * date and a **time**, and no departure at all — asking a restaurant guest when
 * they are leaving is how a form tells somebody it was not written for them.
 * `mode` carries that, and generation sets it from the business type.
 */
class EnquiryBlock extends BlockDefinition
{
    public const MODE_STAY = 'stay';

    public const MODE_VISIT = 'visit';

    public function type(): string
    {
        return 'enquiry';
    }

    public function label(): string
    {
        return 'Enquiry form';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:300'],
            'mode' => ['nullable', 'string', 'in:'.self::MODE_STAY.','.self::MODE_VISIT],
        ];
    }

    public function isFilled(array $data): bool
    {
        // Whether this is worth rendering is a question about the listing
        // behind the site, not about this payload — an enquiry with nowhere to
        // go is worse than no form. The renderer answers it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'heading' => 'Request availability',
            'intro' => null,
            'mode' => self::MODE_STAY,
        ];
    }
}

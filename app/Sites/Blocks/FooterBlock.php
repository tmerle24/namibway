<?php

namespace App\Sites\Blocks;

/**
 * The legal foot of the page — and the one block with liability behind it.
 *
 * **The system frames it; it does not write it.** These are slots for the
 * owner's own particulars — registered name, registration number, the person
 * responsible — and links to their own imprint, privacy policy and terms. No
 * legal text is generated, because generated text that reads like legal advice
 * is legal advice with nobody behind it.
 *
 * What a Namibian tourism business must state here, and who counts as the data
 * controller when NamibWay hosts the page and processes the booking while the
 * owner operates the business, are questions for a lawyer. They are recorded as
 * open in WEBSITE_BUILDER.md §5c and are not answered in code.
 */
class FooterBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'footer';
    }

    public function label(): string
    {
        return 'Footer';
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:160'],
            'registration' => ['nullable', 'string', 'max:120'],
            'responsible_person' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:600'],
            'links' => ['array', 'max:6'],
            'links.*.label' => ['required', 'string', 'max:60'],
            'links.*.href' => ['required', 'string', 'max:2048'],
        ];
    }

    public function isFilled(array $data): bool
    {
        // Always rendered. A page has a foot whatever is in it, and the
        // copyright line and business name come from the site rather than from
        // this payload.
        return true;
    }

    public function defaults(): array
    {
        return [
            'legal_name' => null,
            'registration' => null,
            'responsible_person' => null,
            'note' => null,
            'links' => [],
        ];
    }
}

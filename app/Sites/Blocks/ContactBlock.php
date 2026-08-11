<?php

namespace App\Sites\Blocks;

/**
 * How to reach a person: phone, email, and the WhatsApp button the flyer sells.
 *
 * The contact form is on the flyer too ("a contact form that arrives in your
 * email") and renders here, but submissions are not wired in this slice — the
 * form needs a session, a rate limit and spam protection that does not phone a
 * tracking service, and doing that badly is worse than a fortnight later. Until
 * then the block renders its direct channels, which are what a Namibian
 * business gets contacted through anyway.
 */
class ContactBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'contact';
    }

    public function label(): string
    {
        return 'Contact';
    }

    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:300'],
            'show_form' => ['boolean'],
        ];
    }

    public function isFilled(array $data): bool
    {
        // Same as location: the channels live on the site. A site with no
        // phone, no email and no WhatsApp has no contact block worth showing,
        // and the renderer is what knows that.
        return true;
    }

    public function defaults(): array
    {
        return ['heading' => 'Get in touch', 'intro' => null, 'show_form' => false];
    }
}

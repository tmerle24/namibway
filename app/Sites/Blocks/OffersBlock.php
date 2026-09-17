<?php

namespace App\Sites\Blocks;

/**
 * What the business sells, one card each: a tour, a package, a service.
 *
 * The price list answers "how much", in rows. This answers "what is it and why
 * would I want it" — a picture, a few sentences, how long it takes and what it
 * starts at — which is how a tour operator, an activity or a workshop is
 * actually chosen. Added 2026-09-10, when the first tour operator's site had
 * nowhere to put its tours.
 *
 * Every card asks for the same thing: the page's own contact form, with the
 * card's title carried into the message (see partials/motion). A card cannot
 * sell on its own — there is one form per site, and that is the rule this
 * leans on rather than works around.
 */
class OffersBlock extends BlockDefinition
{
    public const MAX_ITEMS = 12;

    public function type(): string
    {
        return 'offers';
    }

    public function label(): string
    {
        return 'Offers';
    }

    public function navDefault(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'],
            'button_label' => ['nullable', 'string', 'max:24'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.title' => ['required', 'string', 'max:100'],
            'items.*.text' => ['nullable', 'string', 'max:700'],
            // Strings, like the price list: "Full day", "from N$ 1 450 pp" and
            // "on request" are all real answers.
            'items.*.duration' => ['nullable', 'string', 'max:40'],
            'items.*.price' => ['nullable', 'string', 'max:40'],
            'items.*.image_id' => ['nullable', 'integer'],
            // The platform listing this card sells, by slug — a tour request
            // form then opens with it chosen. A slug rather than an id, so a
            // package re-imported into another environment still points right.
            'items.*.listing_slug' => ['nullable', 'string', 'max:255'],
            // A page of this site with the whole story (a tour page); the card
            // then links to it.
            'items.*.page_slug' => ['nullable', 'string', 'max:120'],
            'page_button_label' => ['nullable', 'string', 'max:24'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'items');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'intro' => null, 'button_label' => null, 'items' => []];
    }
}

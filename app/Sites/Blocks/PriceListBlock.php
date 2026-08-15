<?php

namespace App\Sites\Blocks;

/**
 * A menu, a rate card, a list of what things cost.
 *
 * Prices here are **typed by us, on the customer's instruction** — they are not
 * read from the booking system and never should be. Two different promises are
 * being kept: the booking block quotes a price somebody can then be charged, so
 * it is read live from the calendar and is right by construction; this is a
 * restaurant's menu or a workshop's hourly rate, which no system of ours holds.
 *
 * Storing the amount as a string is the same decision. "N$ 120", "from N$ 850",
 * "N$ 45 per person, minimum four" are all things a real price list says, and a
 * decimal column would force the third one into a footnote.
 */
class PriceListBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'price_list';
    }

    public function label(): string
    {
        return 'Price list';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'whatsapp_order' => ['boolean'],
            'items' => ['array', 'max:60'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.price' => ['nullable', 'string', 'max:40'],
        ];
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
        return ['heading' => 'Prices', 'note' => null, 'whatsapp_order' => false, 'items' => []];
    }
}

<?php

namespace App\Sites\Blocks;

/**
 * One kind of block: what it may contain, how it is validated, how it renders.
 *
 * The block library is the product. At N$ 399 a month a bespoke site per
 * customer does not survive the margin, so what makes the offer work is a small
 * fixed set of blocks that covers the businesses on the flyer and nothing else.
 * Every per-customer block added here is margin spent.
 *
 * A block type is a class and a line in BlockRegistry — never a migration, and
 * never a column. That is the whole reason the payload is JSON.
 *
 * ## The rules() contract
 *
 * Rules are written against the payload as if it were the whole request, keyed
 * without any prefix ('headline', 'items.*.title'). Nothing writes a block
 * without passing them, so the database's inability to check the payload is
 * paid for exactly once, here, where the shape is known.
 *
 * No rule anywhere in this library accepts markup from outside the rich-text
 * path. An owner who cannot introduce code cannot introduce a vulnerability
 * into a page we serve under our own certificate.
 */
abstract class BlockDefinition
{
    /** The stored `type` string. Stable — it is data on live sites. */
    abstract public function type(): string;

    /** What this block is called where a human chooses one. */
    abstract public function label(): string;

    /**
     * Laravel validation rules for the payload.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Whether this block has enough content to be worth rendering.
     *
     * A block with nothing in it must not appear as an empty band — that is
     * what makes a generated site look unfinished rather than sparse. Sites
     * generated from a thin listing rely on this heavily.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function isFilled(array $data): bool;

    /**
     * The payload a freshly created block starts with — the placeholder a
     * customer without a listing begins from.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [];
    }

    /**
     * Which payload keys carry markup, and are therefore rendered unescaped.
     *
     * Named here rather than assumed anywhere else, because this is the list
     * SiteBlock runs through the purifier before it writes. Everything not on
     * it is text and is escaped by the view — the two must not be confused, and
     * adding a key here is the whole of what it takes to make a field
     * rich-text.
     *
     * @return array<int, string>
     */
    public function richTextFields(): array
    {
        return [];
    }

    public function view(): string
    {
        return 'sites.blocks.'.$this->type();
    }

    /**
     * Whether the block needs a picture to be worth anything. Used by
     * generation to explain a switched-off block in terms somebody can act on
     * ("no publishable photograph") rather than as a bare skip.
     */
    public function needsImage(): bool
    {
        return false;
    }

    /**
     * Whether this block type appears in the site navigation by default.
     *
     * Blocks that override this to true show up automatically when placed on a
     * page. Blocks that return false are opt-in: the editor must switch
     * nav_visible on for a specific block instance.
     *
     * Hero and Footer override nothing — they are structural and never listed.
     */
    public function navDefault(): bool
    {
        return false;
    }

    /**
     * Validation rules shared by every block that can appear in the nav.
     * Merge into rules() for all content blocks (not hero/footer).
     *
     * @return array<string, mixed>
     */
    public function navRules(): array
    {
        return [
            'nav_visible' => ['nullable', 'boolean'],
            'nav_label' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @param array<string, mixed> $data */
    protected function filled(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value !== [] : filled($value);
    }
}

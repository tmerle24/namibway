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

    /** @param array<string, mixed> $data */
    protected function filled(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value !== [] : filled($value);
    }
}

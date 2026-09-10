<?php

namespace App\Sites\Blocks;

/**
 * The questions the business answers on the phone every week, answered once.
 *
 * Rendered as `<details>` — open and close without a line of JavaScript, and
 * every answer is in the page for a search engine and for a browser with
 * scripting off. Answers are plain text with line breaks, not markup: an FAQ
 * that needs formatting has become a page. Added 2026-09-10.
 */
class FaqBlock extends BlockDefinition
{
    public const MAX_ITEMS = 20;

    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ';
    }

    public function navDefault(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.question' => ['required', 'string', 'max:160'],
            'items.*.answer' => ['required', 'string', 'max:1200'],
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
        return ['heading' => 'Good to know', 'items' => []];
    }
}

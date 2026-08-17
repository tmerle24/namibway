<?php

namespace App\Sites\Blocks;

/**
 * A heading and some prose — the block that exists so we never need a bespoke
 * one.
 *
 * Whatever a business needs to say that none of the other ten blocks say (a
 * cancellation policy, what to bring, how the workshop handles warranty work)
 * goes here rather than becoming a twelfth block type for one customer.
 */
class RichTextBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Text';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:8000'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'body');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'body' => null];
    }

    /**
     * @return array<int, string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }
}

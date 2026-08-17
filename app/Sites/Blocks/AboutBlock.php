<?php

namespace App\Sites\Blocks;

/**
 * Who this business is, in prose, beside one picture.
 *
 * The body is the one field on a site that carries markup, and it goes through
 * the same purifier allow-list as a listing description
 * (Listing::sanitizeRichText) — headings, emphasis, lists and links, nothing
 * that executes.
 */
class AboutBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'about';
    }

    public function label(): string
    {
        return 'About';
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
            'eyebrow' => ['nullable', 'string', 'max:60'],
            'heading' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:8000'],
            'image_id' => ['nullable', 'integer'],
            'image_side' => ['nullable', 'string', 'in:left,right'],
            'slideshow' => ['boolean'],
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
        return [
            'eyebrow' => null,
            'heading' => 'About us',
            'body' => null,
            'image_id' => null,
            'image_side' => 'right',
            'slideshow' => true,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }
}

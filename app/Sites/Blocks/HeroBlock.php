<?php

namespace App\Sites\Blocks;

/**
 * The first thing anybody sees, and the only block allowed to cost real bytes.
 *
 * One picture, one sentence, one thing to do. The image is the site's largest
 * download by an order of magnitude, so it is the only one loaded eagerly and
 * the only one with a `srcset` reaching the top of the width ladder; every
 * other picture on the page waits.
 */
class HeroBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Hero';
    }

    public function rules(): array
    {
        return [
            'image_id' => ['nullable', 'integer'],
            'eyebrow' => ['nullable', 'string', 'max:60'],
            'headline' => ['nullable', 'string', 'max:120'],
            'subline' => ['nullable', 'string', 'max:240'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'cta_href' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function isFilled(array $data): bool
    {
        // A headline alone still makes a usable hero — the band renders on the
        // ground colour instead of a photograph, which is a deliberate look
        // rather than a broken one. Nothing at all does not.
        return $this->filled($data, 'headline');
    }

    public function needsImage(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return [
            'image_id' => null,
            'eyebrow' => null,
            'headline' => null,
            'subline' => null,
            'cta_label' => null,
            'cta_href' => null,
        ];
    }
}

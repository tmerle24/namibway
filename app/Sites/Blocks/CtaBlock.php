<?php

namespace App\Sites\Blocks;

/**
 * One line and one button, near the end.
 *
 * `href` is validated as a URL or a same-page anchor and nothing else — no
 * `javascript:`, no `data:`. A link target is the one field in this library
 * where a string typed by somebody could otherwise become code.
 */
class CtaBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'cta';
    }

    public function label(): string
    {
        return 'Call to action';
    }

    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:300'],
            'label' => ['nullable', 'string', 'max:40'],
            'href' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'heading') || $this->filled($data, 'label');
    }

    public function defaults(): array
    {
        return ['heading' => null, 'text' => null, 'label' => null, 'href' => null];
    }
}

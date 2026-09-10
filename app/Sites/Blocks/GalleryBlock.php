<?php

namespace App\Sites\Blocks;

/**
 * The pictures, below the fold and lazy every one of them.
 *
 * The first VISIBLE are shown; the rest wait behind "Show all" and load only
 * when opened, so the first view costs the same at 48 pictures as at nine.
 * Was a hard cap of twelve, which cut a real customer's gallery off without
 * saying so (2026-09-10).
 */
class GalleryBlock extends BlockDefinition
{
    public const MAX_IMAGES = 48;

    /** Shown before "Show all": one feature tile plus eight, a full 3×4 grid. */
    public const VISIBLE = 9;

    public function type(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Gallery';
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
            'image_ids' => ['array', 'max:'.self::MAX_IMAGES],
            'image_ids.*' => ['integer'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'image_ids');
    }

    public function needsImage(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'image_ids' => []];
    }
}

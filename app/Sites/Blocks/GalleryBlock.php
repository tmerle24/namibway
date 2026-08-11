<?php

namespace App\Sites\Blocks;

/**
 * The pictures, below the fold and lazy every one of them.
 *
 * Capped at twelve. Not an arbitrary number: past that the block stops being a
 * gallery and becomes a download, and on the connection the flyer promises to
 * work on, an unbounded gallery is the single easiest way to make a fast site
 * slow.
 */
class GalleryBlock extends BlockDefinition
{
    public const MAX_IMAGES = 12;

    public function type(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Gallery';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'image_ids' => ['array', 'max:'.self::MAX_IMAGES],
            'image_ids.*' => ['integer'],
        ];
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

<?php

namespace App\Sites\Blocks;

/**
 * Short clips the business filmed itself — played when somebody asks for them.
 *
 * WEBSITE_BUILDER.md rules out video *backgrounds*, and this is not one: a
 * video here costs nothing until it is tapped. With a poster it is
 * `preload="none"`, so the first view downloads one thumbnail; without one it
 * asks for the metadata and the first frame, a few kilobytes, so the slot is
 * not a black rectangle. Never autoplay — on a prepaid bundle a clip that
 * plays itself is a clip the visitor paid for without choosing to.
 *
 * The file is stored under the site's own media prefix on R2 and served from
 * there, never through PHP. `key` is that bucket key, written by the upload in
 * the panel — the payload holds no URLs, so no typed string can point a video
 * tag somewhere else. Added 2026-09-10.
 */
class VideoBlock extends BlockDefinition
{
    public const MAX_ITEMS = 6;

    public function type(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
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
            'intro' => ['nullable', 'string', 'max:400'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            // A bucket key, not a URL: see the class comment.
            'items.*.key' => ['required', 'string', 'max:500', 'not_regex:/^[a-z][a-z0-9+.-]*:/i'],
            'items.*.poster_image_id' => ['nullable', 'integer'],
            'items.*.caption' => ['nullable', 'string', 'max:140'],
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
        return ['heading' => null, 'intro' => null, 'items' => []];
    }
}

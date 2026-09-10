<?php

namespace App\Sites\Import;

/**
 * What a website package would do (or did). Plain arrays and counts only, so
 * the import page can keep it between Livewire requests.
 */
class SitePackagePlan
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, mixed>|null */
    public ?array $partner = null;

    /** @var array<string, mixed>|null */
    public ?array $listing = null;

    /** @var array<string, mixed>|null */
    public ?array $site = null;

    public int $imagesNew = 0;

    public int $imagesUpdated = 0;

    public int $videos = 0;

    /** @var list<array{type: string, label: string, action: string}> */
    public array $blocks = [];

    public bool $written = false;

    public ?int $siteId = null;

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'entities' => array_values(array_filter([$this->partner, $this->listing, $this->site])),
            'images_new' => $this->imagesNew,
            'images_updated' => $this->imagesUpdated,
            'videos' => $this->videos,
            'blocks' => $this->blocks,
            'written' => $this->written,
        ];
    }
}

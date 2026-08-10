<?php

namespace App\Services\ImportExport;

/**
 * One spreadsheet row after validation: what it would do, and why it can't.
 * Built by ListingImporter::plan() and applied — unchanged — by apply(), so
 * what the preview shows is exactly what gets written.
 */
final class PlannedRow
{
    /** @var list<FieldChange> */
    public array $changes = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, mixed> */
    public array $attributes = [];

    /** @var array<string, string|null> Translatable attributes; null clears the translation. */
    public array $translations = [];

    public ?int $listingId = null;

    /** Folder in the photo ZIP whose images replace this listing's photos. */
    public ?string $photoFolder = null;

    /** Set by apply() once the listing is saved, so room rows can find a just-created listing. */
    public ?int $savedListingId = null;

    public bool $isNew = false;

    public string $name = '';

    public function __construct(public readonly int $line) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** A valid row that would actually write something. */
    public function isApplicable(): bool
    {
        return $this->isValid() && ($this->isNew || $this->changes !== []);
    }
}

<?php

namespace App\Services\ImportExport;

/**
 * One row of the RoomTypes sheet after validation. `listingRow` is set when the
 * row belongs to a listing this same import is about to create — the listing id
 * doesn't exist yet at planning time, so apply() reads it back from that row
 * once the listing has been saved.
 */
final class PlannedRoomRow
{
    /** @var list<FieldChange> */
    public array $changes = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, mixed> */
    public array $attributes = [];

    public ?int $listingId = null;

    public ?PlannedRow $listingRow = null;

    public string $listingName = '';

    public string $code = '';

    public ?int $roomTypeId = null;

    /** Folder in the photo ZIP whose images replace this room's gallery. */
    public ?string $photoFolder = null;

    public bool $isNew = false;

    public function __construct(public readonly int $line) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isApplicable(): bool
    {
        return $this->isValid() && ($this->isNew || $this->changes !== []);
    }

    public function label(): string
    {
        return $this->listingName === '' ? $this->code : "{$this->listingName} · {$this->code}";
    }
}

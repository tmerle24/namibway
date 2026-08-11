<?php

namespace App\Filament\Partner\Support;

/**
 * One line of the Getting started checklist. Done or not is derived from the
 * data every time it is read, never stored — see PropertySetup.
 */
class SetupStep
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $body,
        public readonly bool $done,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
    ) {}

    public function hasAction(): bool
    {
        return $this->actionLabel !== null && $this->actionUrl !== null;
    }
}

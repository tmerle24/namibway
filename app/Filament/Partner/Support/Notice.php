<?php

namespace App\Filament\Partner\Support;

use App\Enums\NoticeLevel;

/**
 * One thing the partner panel wants to tell an operator, with — where there is
 * one — the screen that answers it.
 *
 * A notice with no way to act on it is a complaint, so the link is part of the
 * shape rather than an afterthought: a notice this system raises should either
 * be resolvable in one click or lead to a page that says what to do next.
 */
class Notice
{
    public function __construct(
        /** Stable identifier, for tests and for anything that later wants to dismiss one. */
        public readonly string $key,
        public readonly NoticeLevel $level,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
    ) {}

    public function hasAction(): bool
    {
        return $this->actionLabel !== null && $this->actionUrl !== null;
    }
}

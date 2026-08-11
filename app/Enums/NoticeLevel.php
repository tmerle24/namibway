<?php

namespace App\Enums;

/**
 * How loudly a notice on the partner dashboard speaks.
 *
 * Three levels and no more. A scale with five steps ends up with everything at
 * step three, and the point of the board is that an operator can tell in one
 * look whether anything needs them today.
 */
enum NoticeLevel: string
{
    /** Worth knowing, nothing to do. */
    case Info = 'info';

    /** Something is waiting for the operator before the system can do its job. */
    case Action = 'action';

    /** Something is not working the way the screens otherwise suggest. */
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Note',
            self::Action => 'To do',
            self::Warning => 'Heads up',
        };
    }

    /**
     * A Filament colour name. Deliberately not the panel's primary rust: the
     * notices sit above the arrivals board, and a hint should not look like
     * the brand shouting.
     */
    public function color(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Action => 'primary',
            self::Warning => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Info => 'heroicon-o-information-circle',
            self::Action => 'heroicon-o-check-circle',
            self::Warning => 'heroicon-o-exclamation-triangle',
        };
    }

    /** Loudest first, so the board reads top down. */
    public function weight(): int
    {
        return match ($this) {
            self::Warning => 0,
            self::Action => 1,
            self::Info => 2,
        };
    }
}

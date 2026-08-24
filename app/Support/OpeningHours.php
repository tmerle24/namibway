<?php

namespace App\Support;

/**
 * When a place is open, written the way the rest of the world writes it.
 *
 * The syntax is OpenStreetMap's `opening_hours` — `24/7`,
 * `Mo-Fr 07:00-18:00; Sa 08:00-13:00; Su off` — and it is stored verbatim in
 * one column. Two reasons, both from CLAUDE.md's rule about reaching for the
 * established standard: every source these rows will ever be filled from
 * (OSM itself, a Google Places record, a partner's own site) already speaks
 * it, so an import is a copy rather than a translation; and a per-weekday
 * column layout invents a schema that cannot say "closed on public holidays"
 * or "second Saturday of the month" and then has to grow one anyway.
 *
 * Deviation, written down as the same rule requires: this understands a
 * *subset*. Weekday selectors and clock ranges are parsed; month ranges, week
 * numbers, holiday selectors, sunrise/sunset and open-ended times are not. A
 * string that uses them is rejected rather than half-read — half-understood
 * opening hours are worse than none, because the traveller acts on them. The
 * admin field validates against exactly this parser, so what cannot be read
 * cannot be saved.
 *
 * Days come out as lowercase three-letter keys and times as `HH:MM` strings.
 * Nothing here formats anything for a human: the browser already knows the
 * traveller's locale and gets weekday names from Intl, so a German reader is
 * not shown "Mo-Fr" because an English-speaking content manager typed it.
 */
final readonly class OpeningHours
{
    /** @var array<int, string> The week in order, which is what a day range expands over. */
    public const DAYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];

    /**
     * @param  array<int, array{days: array<int, string>, ranges: array<int, array{0: string, 1: string}>}>  $rules
     */
    private function __construct(
        private string $raw,
        private bool $alwaysOpen,
        private array $rules,
    ) {}

    /**
     * Null for a blank column — which means "nobody has recorded them", not
     * "always closed" — and null for anything this cannot read in full.
     */
    public static function parse(?string $value): ?self
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if ($raw === '24/7') {
            return new self($raw, true, [[
                'days' => self::DAYS,
                'ranges' => [['00:00', '24:00']],
            ]]);
        }

        $rules = [];

        foreach (explode(';', $raw) as $part) {
            $rule = self::parseRule(trim($part));

            if ($rule === null) {
                return null;
            }

            $rules[] = $rule;
        }

        return $rules === [] ? null : new self($raw, false, $rules);
    }

    public static function isValid(string $value): bool
    {
        return self::parse($value) !== null;
    }

    public function isAlwaysOpen(): bool
    {
        return $this->alwaysOpen;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * The shape the browser renders: one entry per rule, days as keys it can
     * turn into localised names, and an empty `ranges` for a day that is
     * explicitly closed.
     *
     * @return array{raw: string, always_open: bool, rules: array<int, array{days: array<int, string>, ranges: array<int, array{0: string, 1: string}>}>}
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'always_open' => $this->alwaysOpen,
            'rules' => $this->rules,
        ];
    }

    /**
     * @return array{days: array<int, string>, ranges: array<int, array{0: string, 1: string}>}|null
     */
    private static function parseRule(string $rule): ?array
    {
        if ($rule === '') {
            return null;
        }

        // A rule is an optional day selector followed by times. Without the
        // selector it applies to the whole week, which is how a single
        // "08:00-17:00" is written.
        if (preg_match('/^((?:[A-Za-z]{2}(?:-[A-Za-z]{2})?)(?:,[A-Za-z]{2}(?:-[A-Za-z]{2})?)*)\s+(.*)$/', $rule, $m) === 1) {
            $days = self::parseDays($m[1]);
            $times = trim($m[2]);
        } else {
            $days = self::DAYS;
            $times = $rule;
        }

        if ($days === null || $times === '') {
            return null;
        }

        $ranges = self::parseTimes($times);

        return $ranges === null ? null : ['days' => $days, 'ranges' => $ranges];
    }

    /**
     * @return array<int, string>|null
     */
    private static function parseDays(string $selector): ?array
    {
        $days = [];

        foreach (explode(',', $selector) as $token) {
            $bounds = array_map(
                fn (string $day): string => mb_strtolower(trim($day)),
                explode('-', $token),
            );

            $first = array_search($bounds[0], self::DAYS, strict: true);

            if ($first === false) {
                return null;
            }

            if (count($bounds) === 1) {
                $days[] = self::DAYS[$first];

                continue;
            }

            if (count($bounds) > 2) {
                return null;
            }

            $last = array_search($bounds[1], self::DAYS, strict: true);

            if ($last === false) {
                return null;
            }

            // Mo-Fr walks forward; Sa-Su and Fr-Mo both wrap, which is legal
            // in the standard and reads the way a sign on a door reads.
            for ($i = $first; ; $i = ($i + 1) % 7) {
                $days[] = self::DAYS[$i];

                if ($i === $last) {
                    break;
                }
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * @return array<int, array{0: string, 1: string}>|null
     */
    private static function parseTimes(string $times): ?array
    {
        if (in_array(mb_strtolower($times), ['off', 'closed'], strict: true)) {
            return [];
        }

        $ranges = [];

        foreach (explode(',', $times) as $range) {
            if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $range, $m) !== 1) {
                return null;
            }

            [, $fromHour, $fromMinute, $toHour, $toMinute] = $m;

            if (! self::isClockTime((int) $fromHour, (int) $fromMinute) || ! self::isClockTime((int) $toHour, (int) $toMinute)) {
                return null;
            }

            $ranges[] = [
                sprintf('%02d:%s', (int) $fromHour, $fromMinute),
                sprintf('%02d:%s', (int) $toHour, $toMinute),
            ];
        }

        return $ranges === [] ? null : $ranges;
    }

    /** 24:00 is a legal end of day in this syntax; 24:30 is not a time. */
    private static function isClockTime(int $hour, int $minute): bool
    {
        if ($hour === 24) {
            return $minute === 0;
        }

        return $hour >= 0 && $hour < 24 && $minute >= 0 && $minute < 60;
    }
}

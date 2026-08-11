<?php

namespace App\Sites\Generation;

/**
 * What generation did, and — more usefully — what it did not.
 *
 * A generated site is a first draft somebody then improves, so the value is in
 * the gaps: this field was left empty because the only text we hold for it came
 * from a directory and is not ours to publish; this photograph could not be
 * copied because it is a stock placeholder rather than the customer's; this
 * block is switched off because there was nothing to put in it. A silent
 * generator produces a site whose holes are discovered by the customer.
 */
class GenerationReport
{
    /** @var array<int, array{field: string, reason: string}> */
    public array $skipped = [];

    /** @var array<int, string> */
    public array $written = [];

    /** @var array<int, array{field: string, reason: string}> */
    public array $kept = [];

    /** @var array<int, string> */
    public array $disabledBlocks = [];

    /** @var array<int, string> */
    public array $shortened = [];

    public int $imagesCopied = 0;

    public function wrote(string $field): void
    {
        $this->written[] = $field;
    }

    public function skip(string $field, string $reason): void
    {
        $this->skipped[] = ['field' => $field, 'reason' => $reason];
    }

    /**
     * A field generation would have written but left alone because somebody
     * edited it since. This is the point of re-running: the draft is meant to
     * be improved, and improvement that gets overwritten is worse than no
     * regeneration at all. Same rule as the namibweb importer.
     */
    public function keptEdit(string $field, string $reason): void
    {
        $this->kept[] = ['field' => $field, 'reason' => $reason];
    }

    public function disabled(string $type): void
    {
        $this->disabledBlocks[] = $type;
    }

    /**
     * A listing field that did not fit the block it was written into.
     *
     * Reported rather than silent, because the shortened text is now the
     * website's text: somebody should read it once and decide whether the cut
     * landed somewhere sensible.
     */
    public function shortened(string $field, int $max): void
    {
        $this->shortened[] = "{$field} — shortened to {$max} characters to fit";
    }
}

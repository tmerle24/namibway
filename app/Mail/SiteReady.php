<?php

namespace App\Mail;

use App\Models\Site;
use App\Sites\Generation\GenerationReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your website is ready to look at."
 *
 * The link and the gaps, in that order. What generation could not fill is the
 * useful part of this mail — it is the list somebody works through before the
 * site goes anywhere near a customer, and burying it under congratulations
 * would waste the one moment when they are looking.
 */
class SiteReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly GenerationReport $report,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your website draft: '.$this->site->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sites.ready',
            with: [
                'site' => $this->site,
                'url' => $this->site->publicUrl(),
                'gaps' => collect($this->report->skipped)
                    ->map(fn (array $row): string => $row['reason'])
                    ->unique()
                    ->values()
                    ->all(),
                'empty' => array_values(array_unique($this->report->disabledBlocks)),
            ],
        );
    }
}

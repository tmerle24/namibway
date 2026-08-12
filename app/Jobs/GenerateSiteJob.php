<?php

namespace App\Jobs;

use App\Mail\SiteReady;
use App\Models\Listing;
use App\Models\Site;
use App\Models\User;
use App\Sites\Generation\GenerationReport;
use App\Sites\Generation\SiteGenerator;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One click, and a website exists.
 *
 * ## Why this is a job and not a controller action
 *
 * Generation copies photographs — and now, where they cannot be copied inside
 * the bucket, downloads them. A lodge with a full gallery is a dozen round
 * trips. That is a few seconds: long enough that a button which waits feels
 * broken, and long enough that a request timeout would leave a half-built site.
 *
 * ## Why it reports back twice
 *
 * Because it did not, and that was the bug. The button said "we will email
 * you", the job then skipped every photograph, and nothing in the panel said
 * so — the site was empty and the only account of why sat in an unread inbox.
 * A background job that can partly fail has to say so where the person who
 * pressed the button is looking, so the outcome now arrives as a panel
 * notification as well as the mail, and a failure arrives at all rather than
 * disappearing into the queue log.
 */
class GenerateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Listing $listing,
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $generator = new SiteGenerator;
        $site = $generator->fromListing($this->listing);
        $report = $generator->report();

        $user = $this->user();

        if ($user === null) {
            return;
        }

        Mail::to($user->email)->send(new SiteReady($site, $report));

        $this->notify($user, $site, $report);
    }

    /**
     * A job that dies has to say so too. Without this the queue swallows it and
     * the person who pressed the button waits for a mail that is never coming.
     */
    public function failed(?Throwable $exception): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('The website could not be built')
            ->body($exception?->getMessage() ?? 'The job failed without saying why.')
            ->danger()
            ->sendToDatabase($user);
    }

    private function notify(User $user, Site $site, GenerationReport $report): void
    {
        $problems = count($report->skipped);

        $body = $report->imagesCopied === 1
            ? '1 photograph imported.'
            : $report->imagesCopied.' photographs imported.';

        if ($problems > 0) {
            // The count, not the list: a notification is a nudge, and the
            // reasons are in the mail. But an empty site with a silent green
            // tick is exactly what this exists to prevent, so the number is
            // always here.
            $body .= ' '.$problems.' '.($problems === 1 ? 'thing' : 'things')
                .' could not be filled in — the email says which.';
        }

        Notification::make()
            ->title($report->imagesCopied === 0
                ? 'Website built, without any photographs'
                : 'Website built: '.$site->name)
            ->body($body)
            ->icon('heroicon-o-globe-alt')
            ->color($report->imagesCopied === 0 ? 'warning' : 'success')
            ->actions([
                Action::make('open')
                    ->label('Open it')
                    ->url($site->publicUrl(), shouldOpenInNewTab: true)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    private function user(): ?User
    {
        return $this->userId === null ? null : User::find($this->userId);
    }
}

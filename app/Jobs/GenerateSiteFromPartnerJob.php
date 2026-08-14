<?php

namespace App\Jobs;

use App\Enums\BusinessType;
use App\Mail\SiteReady;
use App\Models\Partner;
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
 * Builds a website seeded from a partner record rather than a listing.
 *
 * Used for partners who have no listing on the travel platform — a shop,
 * a boutique, a service business. The job shape is the same as
 * GenerateSiteJob; the difference is the source and the fact that there
 * are no photographs to copy, so it finishes faster.
 */
class GenerateSiteFromPartnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Partner $partner,
        public readonly BusinessType $type,
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $generator = new SiteGenerator;
        $site = $generator->fromPartner($this->partner, $this->type);
        $report = $generator->report();

        $user = $this->user();

        if ($user === null) {
            return;
        }

        Mail::to($user->email)->send(new SiteReady($site, $report));

        $this->notify($user, $site, $report);
    }

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

        $body = $problems > 0
            ? $problems.' '.($problems === 1 ? 'thing' : 'things').' could not be filled in — the email says which.'
            : 'Ready to open and fill in.';

        Notification::make()
            ->title('Website built: '.$site->name)
            ->body($body)
            ->icon('heroicon-o-globe-alt')
            ->color('success')
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

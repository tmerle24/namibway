<?php

namespace App\Filament\Support;

use App\Jobs\GenerateSiteJob;
use App\Models\Listing;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * The one-click website button, in one place for both panels.
 *
 * The admin sees it on any listing; a partner sees it on their own. They do
 * exactly the same thing and must keep doing exactly the same thing — the
 * moment the two diverge, "the customer can also edit it themselves" turns into
 * two products with one price.
 *
 * ## Why it queues
 *
 * Generation copies photographs between bucket objects, which for a lodge with
 * a full gallery is seconds rather than milliseconds. A button that waits reads
 * as broken, and a request that times out leaves a half-built site. So the
 * click returns at once and the mail follows — which is also what was promised.
 *
 * ## Why pressing it twice is safe
 *
 * The second press is a refresh, not a second website. SiteGenerator matches on
 * the listing, updates what it wrote last time and leaves anything edited since
 * exactly as it is. The label changes to say so, because a button that says
 * "create" over an existing site invites somebody to expect a clean slate.
 */
class CreateWebsiteAction
{
    public static function make(string $name = 'create_website'): Action
    {
        return Action::make($name)
            ->label(fn (Listing $record): string => self::siteFor($record) === null
                ? 'Create website'
                : 'Rebuild website')
            ->icon('heroicon-o-globe-alt')
            ->color(fn (Listing $record): string => self::siteFor($record) === null ? 'success' : 'gray')
            ->requiresConfirmation()
            ->modalHeading(fn (Listing $record): string => self::siteFor($record) === null
                ? 'Create a website for '.$record->name
                : 'Rebuild the website for '.$record->name)
            ->modalDescription(fn (Listing $record): string => self::siteFor($record) === null
                ? 'We build a private draft from this listing — its text, its photographs, its address. '
                    .'Nothing becomes public, and an email follows in a minute or two with the link and '
                    .'a list of anything we could not fill in.'
                : 'This refreshes the draft from the listing. Anything edited on the website since it was '
                    .'built is left exactly as it is, and the email says what was kept.')
            ->modalSubmitActionLabel(fn (Listing $record): string => self::siteFor($record) === null
                ? 'Build it'
                : 'Refresh it')
            ->action(function (Listing $record): void {
                GenerateSiteJob::dispatch($record, self::notifyAddress($record));

                Notification::make()
                    ->title('Building the website')
                    ->body('It takes a minute. We will email the link when it is ready.')
                    ->success()
                    ->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }

    /**
     * Who hears about it.
     *
     * Whoever pressed the button, because they are the one waiting for it. Not
     * the partner's own address: an admin building a draft to show a business
     * that has not agreed to anything yet must not have that email land on the
     * business's desk.
     */
    private static function notifyAddress(Listing $listing): ?string
    {
        $email = auth()->user()?->email;

        return is_string($email) && $email !== '' ? $email : null;
    }
}

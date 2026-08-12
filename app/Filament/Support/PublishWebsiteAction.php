<?php

namespace App\Filament\Support;

use App\Enums\SiteStatus;
use App\Models\Listing;
use App\Models\Site;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Filament\Actions\Action as PageAction;
use Filament\Notifications\Notification;

/**
 * Take a draft live.
 *
 * The one transition with somebody else's name on it. Until it happens the page
 * is research, reachable only by its token; afterwards it is a business's public
 * website, indexable, on its own address. So it asks first, and it goes through
 * PublishGate rather than setting a column — the gate is what refuses a page
 * still leaning on a photograph we may show while prospecting and may not hand
 * over, and it says which one.
 *
 * Admin only, deliberately. Publishing under a business's name is not something
 * the platform should let anybody do on their behalf, and the owner's own copy
 * of the build button is switched off anyway until there is a subscription.
 */
class PublishWebsiteAction
{
    public static function header(string $name = 'publish_website'): PageAction
    {
        return PageAction::make($name)
            ->label(fn (Listing $record): string => self::siteFor($record)?->isPublished() === true
                ? 'Unpublish'
                : 'Publish website')
            ->icon('heroicon-o-rocket-launch')
            ->color(fn (Listing $record): string => self::siteFor($record)?->isPublished() === true
                ? 'gray'
                : 'success')
            ->visible(fn (Listing $record): bool => self::siteFor($record) !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Listing $record): string => self::siteFor($record)?->isPublished() === true
                ? 'Take this website back to a draft'
                : 'Publish this website')
            ->modalDescription(fn (Listing $record): string => self::siteFor($record)?->isPublished() === true
                ? 'It stops being reachable without its preview token, and asks search engines to forget it.'
                : 'It becomes publicly reachable at its own address and indexable by search engines. '
                    .'Only do this once the business has seen it and agreed.')
            ->action(function (Listing $record): void {
                $site = self::siteFor($record);

                if ($site === null) {
                    return;
                }

                if ($site->isPublished()) {
                    $site->forceFill(['status' => SiteStatus::Draft, 'published_at' => null])->save();

                    Notification::make()->title('Back to a draft')->success()->send();

                    return;
                }

                try {
                    app(PublishGate::class)->publish($site);
                } catch (CannotPublish $e) {
                    Notification::make()
                        ->title('Not published')
                        ->body(implode('; ', $e->blockers))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Live')
                    ->body($site->refresh()->publicUrl())
                    ->success()
                    ->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

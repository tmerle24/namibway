<?php

namespace App\Filament\Support;

use App\Enums\SiteStatus;
use App\Jobs\GenerateSiteJob;
use App\Models\Listing;
use App\Models\Site;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Everything about this listing's website, on one tab.
 *
 * It was spread across the page header, which is the wrong place: a header is
 * for what the page is, and this is a subject of its own — the same reason
 * Media and Booking already have tabs. Somebody looking after a customer's
 * website wants the state, the address and the three things they can do to it
 * in one place, not distributed across the furniture.
 *
 * The buttons here and the ones in the listings table do the same work through
 * the same code. Filament has three Action classes — table, page, form — which
 * is a good reason to keep the *behaviour* in one function each and let these
 * only choose the flavour.
 */
class WebsiteTab
{
    public static function make(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website')
            ->icon('heroicon-o-globe-alt')
            // Nothing to show before the listing exists: a website is built
            // from a record, and on the create form there is not one yet.
            ->visibleOn('edit')
            ->schema([
                Forms\Components\Placeholder::make('website_state')
                    ->label('Status')
                    ->content(fn (?Listing $record): string => match (true) {
                        $record === null => '—',
                        self::siteFor($record) === null => 'No website yet.',
                        self::siteFor($record)?->isPublished() === true => 'Published — live at its own address and indexable.',
                        default => 'Draft — opens at its own address, but tells search engines to ignore it.',
                    }),

                Forms\Components\Placeholder::make('website_address')
                    ->label('Address')
                    ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null)
                    ->content(function (?Listing $record): HtmlString {
                        $site = $record === null ? null : self::siteFor($record);

                        if ($site === null) {
                            return new HtmlString('—');
                        }

                        $url = e($site->publicUrl());

                        return new HtmlString(
                            '<a href="'.$url.'" target="_blank" rel="noopener" class="underline">'.$url.'</a>'
                            .(blank($site->host)
                                ? '<p class="text-sm opacity-70 mt-1">No host yet — run <code>sites:hosts --backfill</code> once the wildcard domain is configured.</p>'
                                : '')
                        );
                    }),

                Forms\Components\Actions::make([
                    Action::make('build_website')
                        ->label(fn (?Listing $record): string => $record !== null && self::siteFor($record) !== null
                            ? 'Rebuild from this listing'
                            : 'Create website')
                        ->icon('heroicon-o-globe-alt')
                        ->color(fn (?Listing $record): string => $record !== null && self::siteFor($record) !== null ? 'gray' : 'success')
                        ->requiresConfirmation()
                        ->modalDescription('We build from this listing — its text, its photographs, its address. '
                            .'Anything already edited on the website is left alone, and the bell reports what happened.')
                        ->action(function (?Listing $record): void {
                            if ($record === null) {
                                return;
                            }

                            $userId = auth()->id();

                            GenerateSiteJob::dispatch($record, is_numeric($userId) ? (int) $userId : null);

                            Notification::make()
                                ->title('Building the website')
                                ->body('It takes a minute. The bell will say how it went.')
                                ->success()
                                ->send();
                        }),

                    Action::make('publish_website')
                        ->label(fn (?Listing $record): string => $record !== null && self::siteFor($record)?->isPublished() === true
                            ? 'Unpublish'
                            : 'Publish')
                        ->icon('heroicon-o-rocket-launch')
                        ->color(fn (?Listing $record): string => $record !== null && self::siteFor($record)?->isPublished() === true
                            ? 'danger'
                            : 'success')
                        ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (?Listing $record): string => $record !== null && self::siteFor($record)?->isPublished() === true
                            ? 'It stops being indexable and goes back to being a draft.'
                            : 'It becomes indexable by search engines under this business\'s name. '
                                .'Only do this once they have seen it and agreed.')
                        ->action(fn (?Listing $record) => $record === null ? null : self::togglePublished($record)),

                    Action::make('open_website')
                        ->label('Open')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null)
                        ->url(fn (?Listing $record): ?string => $record === null ? null : self::siteFor($record)?->publicUrl())
                        ->openUrlInNewTab(),
                ]),
            ]);
    }

    private static function togglePublished(Listing $listing): void
    {
        $site = self::siteFor($listing);

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

        Notification::make()->title('Live')->body($site->refresh()->publicUrl())->success()->send();
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

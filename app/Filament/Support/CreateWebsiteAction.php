<?php

namespace App\Filament\Support;

use App\Jobs\GenerateSiteJob;
use App\Models\Listing;
use App\Models\Site;
use Filament\Actions\Action as PageAction;
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
    /**
     * The owner's own copy of the button — visible, and shut until they are a
     * customer.
     *
     * Deliberately not hidden. The whole point of it being there is that the
     * owner sees a website is one click away; hiding it would sell nothing and
     * explain nothing. What holds it shut is the subscription: it was shut for
     * everybody while none existed, and since 2026-08-13 it opens for a partner
     * whose subscription is active.
     *
     * **This is a lock on a door, not a wall.** The same question is asked
     * again inside the action (see configureCreate) — a disabled button is the
     * right thing for an owner to see and the wrong thing to rely on, since a
     * Livewire call arrives from the browser and the browser is not ours.
     */
    public static function locked(string $name = 'create_website'): Action
    {
        return self::make($name)
            ->disabled(fn (Listing $record): bool => ! self::entitled($record))
            ->color(fn (Listing $record): string => self::entitled($record)
                ? (self::siteFor($record) === null ? 'success' : 'gray')
                : 'gray')
            ->tooltip(fn (Listing $record): ?string => self::entitled($record)
                ? null
                : 'Included once your website subscription starts. Order one above and we invoice you.');
    }

    /** Whether this listing's business may have a website built. */
    private static function entitled(Listing $listing): bool
    {
        return $listing->partner?->hasWebsiteEntitlement() === true;
    }

    /**
     * The link to the site itself, once there is one.
     *
     * Only appears where a website exists, so it is also the honest answer to
     * "has this listing got one?" — a greyed-out link would say the same thing
     * less clearly.
     *
     * The address comes from `Site::publicUrl()`, which carries the preview
     * token while the site is a draft. That token is what makes the link work
     * at all, and it is also the reason the tooltip says out loud that a draft
     * is private: anyone holding this URL can see it.
     */
    public static function visit(string $name = 'visit_website'): Action
    {
        // Switched off rather than hidden, which is the opposite of the header
        // variant below and deliberately so. A row action that appears only on
        // some rows changes how many icons that row has, and every column after
        // it moves — so a table of listings stops lining up and the eye has to
        // re-find each column on every row. Reserving the space costs one grey
        // icon; not reserving it costs the table's alignment.
        return self::configureVisit(Action::make($name))
            ->label('')
            ->disabled(fn (Listing $record): bool => self::siteFor($record) === null);
    }

    /**
     * The same link, in a record page's header.
     *
     * Filament keeps two Action classes — one for tables, one for pages — that
     * share their configuration methods but not an ancestor worth type-hinting.
     * Rather than write the button twice and let the two drift, the shape lives
     * in configureVisit()/configureCreate() and these four methods only pick
     * which class it is poured into. On a page the label is spelled out; in a
     * crowded table row it is an icon.
     */
    public static function visitHeader(string $name = 'visit_website'): PageAction
    {
        // Hidden when there is nothing to open. A page header has no columns to
        // keep in line, and a dead button next to Save is just noise.
        return self::configureVisit(PageAction::make($name))
            ->label('Open website')
            ->visible(fn (Listing $record): bool => self::siteFor($record) !== null);
    }

    public static function make(string $name = 'create_website'): Action
    {
        return self::configureCreate(Action::make($name));
    }

    public static function makeHeader(string $name = 'create_website'): PageAction
    {
        return self::configureCreate(PageAction::make($name));
    }

    /**
     * @template T of Action|PageAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configureVisit(Action|PageAction $action): Action|PageAction
    {
        return $action
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->tooltip(function (Listing $record): string {
                $site = self::siteFor($record);

                return match (true) {
                    $site === null => 'No website for this listing yet',
                    $site->isPublished() => 'Open the website',
                    default => 'Open the private draft — this link works without a password, so treat it like one',
                };
            })
            ->url(fn (Listing $record): ?string => self::siteFor($record)?->publicUrl())
            ->openUrlInNewTab();
    }

    /**
     * @template T of Action|PageAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configureCreate(Action|PageAction $action): Action|PageAction
    {
        return $action
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
                // The lock again, behind the door this time. A partner reaches
                // this through a Livewire call from their own browser, and a
                // disabled attribute is a thing the browser was asked to
                // render, not a thing it can be held to. The admin is not
                // subject to it: we build drafts for prospects who have
                // subscribed to nothing, which is how the product is sold.
                $user = auth()->user();

                if ($user?->partner_id !== null && ! self::entitled($record)) {
                    Notification::make()
                        ->title('Not built')
                        ->body('A website comes with the subscription. Order one and we will invoice you.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // auth()->id() is int|string|null — string for anything keyed by
                // UUID, which this application is not, but the signature does
                // not know that.
                $userId = auth()->id();

                GenerateSiteJob::dispatch($record, is_numeric($userId) ? (int) $userId : null);

                Notification::make()
                    ->title('Building the website')
                    ->body('It takes a minute. The bell up here will say how it went, and the details go to your email.')
                    ->success()
                    ->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

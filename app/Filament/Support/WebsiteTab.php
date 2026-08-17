<?php

namespace App\Filament\Support;

use App\Enums\BusinessType;
use App\Enums\ListingType;
use App\Enums\SiteStatus;
use App\Filament\Resources\ListingResource;
use App\Jobs\GenerateSiteFromPartnerJob;
use App\Jobs\GenerateSiteJob;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Sites\LegalText;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Everything about a listing's or partner's website, on one tab.
 *
 * Works for both Listing and Partner owners — the only difference is which
 * foreign key ties the Site to its source and which generation job runs. All
 * editing actions are identical once the Site is resolved, which is why they
 * live in shared action classes (EditBlocksAction, EditHeroAction, etc.) and
 * this tab just assembles them.
 *
 * Pass a Listing or Partner record to make(); Filament injects the right
 * model into every closure via its type hint.
 */
class WebsiteTab
{
    public static function make(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website')
            ->icon('heroicon-o-globe-alt')
            ->visibleOn('edit')
            ->schema([
                Forms\Components\Placeholder::make('website_state')
                    ->label('Status')
                    ->content(fn (Listing|Partner|null $record): string => match (true) {
                        $record === null => '—',
                        SiteResolver::for($record) === null => 'No website yet.',
                        SiteResolver::for($record)->isPublished() => 'Published — live at its own address and indexable.',
                        default => 'Draft — opens at its own address, but tells search engines to ignore it.',
                    }),

                Forms\Components\Placeholder::make('website_address')
                    ->label('Address')
                    ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null)
                    ->content(function (Listing|Partner|null $record): HtmlString {
                        $site = $record === null ? null : SiteResolver::for($record);

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

                Forms\Components\Placeholder::make('website_terms')
                    ->label('Legal pages')
                    ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null)
                    ->content(function (Listing|Partner|null $record): string {
                        $site = $record === null ? null : SiteResolver::for($record);

                        if ($site === null) {
                            return '—';
                        }

                        return $site->terms_accepted_at === null
                            ? 'We have written the first version of Privacy, the legal notice and the copyright line. '
                                .'Send them to the business to confirm — the site publishes itself when they do.'
                            : 'Confirmed by '.($site->terms_accepted_by ?: 'the business')
                                .' on '.$site->terms_accepted_at->format('j M Y')
                                .($site->terms_version ? ', against terms '.$site->terms_version : '').'.';
                    }),

                Forms\Components\Actions::make([
                    EditContactChannelsAction::make(),

                    EditEnquiryFormAction::make(),

                    EditBlocksAction::make(),

                    EditPagesAction::make(),

                    EditSiteImagesAction::make(),

                    ManageShopProductsAction::make(),

                    Action::make('manage_menu_items')
                        ->label('Menu')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->color('gray')
                        ->visible(function (Listing|Partner|null $record): bool {
                            if ($record === null) {
                                return false;
                            }

                            $listing = $record instanceof Listing
                                ? $record
                                : SiteResolver::for($record)?->sourceListing;

                            return $listing !== null && $listing->type === ListingType::Restaurant;
                        })
                        ->url(function (Listing|Partner|null $record): ?string {
                            if ($record === null) {
                                return null;
                            }

                            $listing = $record instanceof Listing
                                ? $record
                                : SiteResolver::for($record)?->sourceListing;

                            return $listing !== null
                                ? ListingResource::getUrl('edit', ['record' => $listing]).'#relation-manager-menu-items-relation-manager'
                                : null;
                        })
                        ->openUrlInNewTab(),

                    EditHeroAction::make(),

                    EditSiteLogoAction::make(),

                    EditTypographyAction::make(),

                    EditActionButtonsAction::make(),

                    EditCustomDomainAction::make(),

                    EditLegalTextAction::make(),

                    SendTermsConfirmationAction::make(),

                    Action::make('build_website')
                        ->label(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record) !== null
                            ? 'Rebuild from this '.($record instanceof Partner ? 'partner' : 'listing')
                            : 'Create website')
                        ->icon('heroicon-o-globe-alt')
                        ->color(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record) !== null ? 'gray' : 'success')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record) !== null
                            ? 'We rebuild from this source — the text, photographs and address. '
                                .'Anything already edited on the website is left alone, and the bell reports what happened.'
                            : 'We build from this source — its text, its photographs, its address. '
                                .'Nothing becomes public. A notification follows when the draft is ready.')
                        // Partners without a business_type need one to generate — ask for it here
                        // the first time, since it is not derivable from the record automatically.
                        ->form(fn (Listing|Partner|null $record): array => (
                            $record instanceof Partner
                            && SiteResolver::for($record) === null
                            && blank($record->business_type)
                        ) ? [
                            Forms\Components\Select::make('business_type')
                                ->label('Business type')
                                ->options(BusinessType::class)
                                ->required()
                                ->default(BusinessType::Retail->value)
                                ->helperText('Set this once on the partner record to skip this question next time.'),
                        ] : [])
                        ->action(function (Listing|Partner|null $record, array $data): void {
                            if ($record === null) {
                                return;
                            }

                            $userId = auth()->id();
                            $userId = is_numeric($userId) ? (int) $userId : null;

                            if ($record instanceof Partner) {
                                $existing = SiteResolver::for($record);
                                $type = ($existing !== null ? $existing->business_type : null)
                                    ?? (filled($record->business_type) ? BusinessType::from($record->business_type) : null)
                                    ?? (isset($data['business_type']) ? BusinessType::from($data['business_type']) : BusinessType::Retail);

                                GenerateSiteFromPartnerJob::dispatch($record, $type, $userId);
                            } else {
                                GenerateSiteJob::dispatch($record, $userId);
                            }

                            Notification::make()
                                ->title('Building the website')
                                ->body('It takes a minute. The bell will say how it went.')
                                ->success()
                                ->send();
                        }),

                    Action::make('publish_website')
                        ->label(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record)?->isPublished() === true
                            ? 'Unpublish'
                            : 'Publish')
                        ->icon('heroicon-o-rocket-launch')
                        ->color(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record)?->isPublished() === true
                            ? 'danger'
                            : 'success')
                        ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (Listing|Partner|null $record): string => $record !== null && SiteResolver::for($record)?->isPublished() === true
                            ? 'It stops being indexable and goes back to being a draft.'
                            : 'It becomes indexable by search engines under this business\'s name. '
                                .'Only do this once they have seen it and agreed.')
                        ->form(fn (Listing|Partner|null $record): array => $record !== null && SiteResolver::for($record)?->isPublished() === true
                            ? []
                            : [
                                Forms\Components\Checkbox::make('terms')
                                    ->label(fn (): string => 'The business has seen the site and its legal pages, and accepts the NamibWay website terms'
                                        .(LegalText::termsUrl() === null ? '.' : ' ('.LegalText::termsUrl().').'))
                                    ->required()
                                    ->accepted(),

                                Forms\Components\TextInput::make('confirmed_by')
                                    ->label('Who confirmed')
                                    ->helperText('The person at the business who agreed — a name is enough.')
                                    ->maxLength(255),
                            ])
                        ->action(fn (Listing|Partner|null $record, array $data) => $record === null ? null : self::togglePublished($record, $data)),

                    Action::make('open_website')
                        ->label('Open')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null)
                        ->url(fn (Listing|Partner|null $record): ?string => $record === null ? null : SiteResolver::for($record)?->publicUrl())
                        ->openUrlInNewTab(),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function togglePublished(Listing|Partner $owner, array $data = []): void
    {
        $site = SiteResolver::for($owner);

        if ($site === null) {
            return;
        }

        if ($site->isPublished()) {
            $site->forceFill(['status' => SiteStatus::Draft, 'published_at' => null])->save();

            Notification::make()->title('Back to a draft')->success()->send();

            return;
        }

        if ($site->terms_accepted_at === null) {
            $confirmedBy = trim((string) ($data['confirmed_by'] ?? ''));

            $site->forceFill([
                'terms_accepted_at' => now(),
                'terms_accepted_by' => $confirmedBy !== '' ? $confirmedBy : null,
            ])->save();
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
}

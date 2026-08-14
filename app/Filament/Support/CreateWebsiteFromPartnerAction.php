<?php

namespace App\Filament\Support;

use App\Enums\BusinessType;
use App\Jobs\GenerateSiteFromPartnerJob;
use App\Models\Partner;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * The one-click "build a website" button for partners who have no listing.
 *
 * Placed on the partner edit page in the admin panel. The admin picks the
 * business type (a partner is not a listing type, so it cannot be derived
 * automatically) and the generator does the rest from the partner's own data.
 *
 * Pressing it a second time refreshes the draft, not creates a second website —
 * the same re-run semantics as CreateWebsiteAction on a listing.
 */
class CreateWebsiteFromPartnerAction
{
    public static function make(string $name = 'create_website_from_partner'): Action
    {
        return Action::make($name)
            ->label(fn (Partner $record): string => self::siteFor($record) === null
                ? 'Create website'
                : 'Rebuild website')
            ->icon('heroicon-o-globe-alt')
            ->color(fn (Partner $record): string => self::siteFor($record) === null ? 'success' : 'gray')
            ->form(fn (Partner $record): array => self::siteFor($record) !== null ? [] : [
                Select::make('business_type')
                    ->label('Business type')
                    ->options(BusinessType::class)
                    ->required()
                    ->default(BusinessType::Retail->value),
            ])
            ->modalHeading(fn (Partner $record): string => self::siteFor($record) === null
                ? 'Create a website for '.$record->name
                : 'Rebuild the website for '.$record->name)
            ->modalDescription(fn (Partner $record): string => self::siteFor($record) === null
                ? 'We build a private draft from this partner — their name, contact details and bio. '
                    .'Nothing becomes public. A notification follows when the draft is ready.'
                : 'This refreshes the draft from the partner\'s data. Anything edited on the website '
                    .'since it was built is left exactly as it is.')
            ->modalSubmitActionLabel(fn (Partner $record): string => self::siteFor($record) === null
                ? 'Build it'
                : 'Refresh it')
            ->action(function (Partner $record, array $data): void {
                $existing = self::siteFor($record);

                $type = $existing !== null
                    ? $existing->business_type
                    : BusinessType::from($data['business_type']);

                $userId = auth()->id();

                GenerateSiteFromPartnerJob::dispatch(
                    $record,
                    $type,
                    is_numeric($userId) ? (int) $userId : null,
                );

                Notification::make()
                    ->title('Building the website')
                    ->body('A notification appears here when it\'s done.')
                    ->success()
                    ->send();
            });
    }

    public static function visit(string $name = 'visit_partner_website'): Action
    {
        return Action::make($name)
            ->label('Open website')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (Partner $record): bool => self::siteFor($record) !== null)
            ->tooltip(function (Partner $record): string {
                $site = self::siteFor($record);

                return match (true) {
                    $site === null => 'No website yet',
                    $site->isPublished() => 'Open the website',
                    default => 'Open the private draft — this link works without a password, so treat it like one',
                };
            })
            ->url(fn (Partner $record): ?string => self::siteFor($record)?->publicUrl())
            ->openUrlInNewTab();
    }

    private static function siteFor(Partner $partner): ?Site
    {
        return Site::where('partner_id', $partner->id)
            ->whereNull('source_listing_id')
            ->first();
    }
}

<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Site;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * The business's own mark, at the top of its own website.
 *
 * Set by the team or by the owner — same editor in both panels, same reason as
 * EditLegalTextAction: the moment the owner's copy and ours diverge, "the
 * customer can also edit it themselves" turns into two products with one price.
 *
 * Absent is a finished state, not a missing one. Without a logo the name is set
 * in the site's display face, which for most Namibian lodges is better than the
 * logo they actually have.
 */
class EditSiteLogoAction
{
    public static function make(string $name = 'edit_logo'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_logo'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => self::siteFor($record) !== null);
    }

    /**
     * @template T of FormAction|PageAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(FormAction|PageAction $action): FormAction|PageAction
    {
        return $action
            ->label('Logo')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->modalHeading('The logo at the top of your website')
            ->modalDescription('Shown in the bar at the top of every page. Leave it empty and your name is '
                .'set in the site\'s own typeface instead, which is often the better answer.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $site = $record === null ? null : self::siteFor($record);

                return $site === null ? [] : ['logo_key' => $site->logo_key];
            })
            ->form([
                Forms\Components\FileUpload::make('logo_key')
                    ->label('Logo')
                    ->image()
                    ->disk('r2')
                    ->directory('sites/logos')
                    ->imageEditor()
                    ->openable()
                    ->fetchFileInformation(false)
                    ->helperText('A wide mark reads best — it is fitted to the height of the bar. '
                        .'A transparent PNG or an SVG sits well over a photograph.'),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                // FileUpload hands back a keyed array of one; the column holds
                // the bucket key on its own.
                $key = $data['logo_key'] ?? null;
                $key = is_array($key) ? (reset($key) ?: null) : $key;

                $site->forceFill(['logo_key' => is_string($key) && $key !== '' ? $key : null])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

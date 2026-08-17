<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
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
 * logo they actually have — which is why the name the bar sets is edited here
 * too. It is the same slot: whatever is in it, this is what somebody looks at
 * to find out whose site they are on.
 */
class EditSiteLogoAction
{
    public static function make(string $name = 'edit_logo'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'edit_logo'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => SiteResolver::for($record) !== null);
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
            ->label('Name and logo')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->modalHeading('What the bar at the top shows')
            ->modalDescription('A logo, or your name set in the site\'s own typeface — which is often the '
                .'better answer. The name here is only for the bar: the full one stays on the page title '
                .'and the legal notice.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (Listing|Partner|null $record): array {
                $site = $record === null ? null : SiteResolver::for($record);

                return $site === null ? [] : [
                    'logo_key' => $site->logo_key,
                    'brand_name' => $site->brand_name,
                    'logo_hero_height' => $site->logo_hero_height,
                    'logo_compact_height' => $site->logo_compact_height,
                ];
            })
            ->form([
                Forms\Components\Textarea::make('brand_name')
                    ->label('Name in the bar')
                    ->rows(2)
                    ->maxLength(120)
                    ->placeholder(fn (Listing|Partner|null $record): string => $record === null
                        ? ''
                        : (string) (SiteResolver::for($record)->name ?? ''))
                    ->helperText('Empty uses the business\'s full name. A line break here is where the '
                        .'name breaks in the bar — which beats letting the browser choose, and is what '
                        .'turned "…Hunting / Safari" into two lines that read. Two lines is the maximum '
                        .'the bar has room for.'),

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

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('logo_hero_height')
                        ->label('Size in the opening screen')
                        ->numeric()
                        ->minValue(32)
                        ->maxValue(300)
                        ->suffix('px')
                        ->placeholder('56')
                        ->helperText('How tall the logo appears before the page is scrolled. Default 56 px.'),

                    Forms\Components\TextInput::make('logo_compact_height')
                        ->label('Size in the sticky bar')
                        ->numeric()
                        ->minValue(24)
                        ->maxValue(48)
                        ->suffix('px')
                        ->placeholder('36')
                        ->helperText('Once the bar turns solid. Max 48 px (fits the 64 px bar). Default 36 px.'),
                ])->hidden(fn (Forms\Get $get): bool => blank($get('logo_key'))),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                // FileUpload hands back a keyed array of one; the column holds
                // the bucket key on its own.
                $key = $data['logo_key'] ?? null;
                $key = is_array($key) ? (reset($key) ?: null) : $key;

                $brand = trim((string) ($data['brand_name'] ?? ''));
                $site->brand_name = $brand === '' ? null : $brand;

                $heroHeight = is_numeric($data['logo_hero_height'] ?? null)
                    ? (int) $data['logo_hero_height']
                    : null;
                $site->logo_hero_height = ($heroHeight !== null && $heroHeight >= 32 && $heroHeight <= 300) ? $heroHeight : null;

                $compactHeight = is_numeric($data['logo_compact_height'] ?? null)
                    ? (int) $data['logo_compact_height']
                    : null;
                $site->logo_compact_height = ($compactHeight !== null && $compactHeight >= 24 && $compactHeight <= 48) ? $compactHeight : null;

                $site->forceFill(['logo_key' => is_string($key) && $key !== '' ? $key : null])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }
}

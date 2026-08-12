<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Site;
use App\Sites\Typography;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * What the website is set in.
 *
 * A business that already has branding has usually already chosen a face, and
 * "you may have our two" is a poor answer to that. So both roles are settable,
 * and the name in the bar gets a third choice of its own because it is the one
 * piece of type that has to survive being white, small, and over an arbitrary
 * photograph.
 *
 * Every option is a stack the reader already has. That is not a limitation to
 * apologise for: it is why these pages paint on the first frame instead of
 * swapping fonts, which matters more on the connections they are built for than
 * any particular face does. A licensed webfont behind one of these keys is a
 * one-line change in App\Sites\Typography when somebody buys one.
 */
class EditTypographyAction
{
    public static function make(string $name = 'edit_typography'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_typography'): PageAction
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
            ->label('Fonts & title size')
            ->icon('heroicon-o-language')
            ->color('gray')
            ->modalHeading('How the website is set')
            ->modalDescription('Leave these alone unless the business already has branding of its own. '
                .'Every face here is one the visitor already has, so the page never waits for a font to '
                .'download.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return [];
                }

                return [
                    'font_display' => $site->font_display ?? Typography::DEFAULT_DISPLAY,
                    'font_body' => $site->font_body ?? Typography::DEFAULT_BODY,
                    'brand_font' => $site->brand_font,
                    'brand_size' => (string) ($site->brand_size ?? ''),
                ];
            })
            ->form([
                Forms\Components\Select::make('font_display')
                    ->label('Headings')
                    ->options(Typography::options())
                    ->helperText('The big type: the opening line, section headings.')
                    ->required(),

                Forms\Components\Select::make('font_body')
                    ->label('Body text')
                    ->options(Typography::options())
                    ->helperText('Everything that is read rather than looked at.')
                    ->required(),

                Forms\Components\Select::make('brand_font')
                    ->label('The name in the bar')
                    ->options(Typography::options())
                    ->placeholder('Same as the body text')
                    ->helperText('Its own choice, because this one has to hold up white and small over a '
                        .'photograph. A plain, slightly heavy face usually wins here.'),

                Forms\Components\Select::make('brand_size')
                    ->label('Size of the name')
                    ->options(Typography::brandSizeOptions())
                    ->helperText('Automatic sizes it by how long the name is, which is what keeps a long '
                        .'one from being cut off beside a full menu. Override it if you would rather.'),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                $size = trim((string) ($data['brand_size'] ?? ''));

                $site->fill([
                    'font_display' => $data['font_display'] ?? null,
                    'font_body' => $data['font_body'] ?? null,
                    // Blank means "same as the body text" and "work it out from
                    // the name" — both are real answers, so both are stored as
                    // null rather than as a value somebody has to keep correct.
                    'brand_font' => filled($data['brand_font'] ?? null) ? $data['brand_font'] : null,
                    'brand_size' => $size === '' ? null : (int) $size,
                ])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

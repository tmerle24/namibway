<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
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
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'edit_typography'): PageAction
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
            ->label('Fonts & title size')
            ->icon('heroicon-o-language')
            ->color('gray')
            ->modalHeading('How the website is set')
            ->modalDescription('Leave these alone unless the business already has branding of its own. '
                .'Every face here is one the visitor already has, so the page never waits for a font to '
                .'download.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (Listing|Partner|null $record): array {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return [];
                }

                return [
                    'font_display' => $site->font_display ?? Typography::DEFAULT_DISPLAY,
                    'font_body' => $site->font_body ?? Typography::DEFAULT_BODY,
                    'brand_font' => $site->brand_font,
                    'brand_size' => (string) ($site->brand_size ?? ''),
                    'brand_size_mobile' => (string) ($site->brand_size_mobile ?? ''),
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
                    ->label('Size of the name — wide screens')
                    ->options(Typography::brandSizeOptions())
                    ->helperText('Automatic sizes it by how long the name is.'),

                Forms\Components\Select::make('brand_size_mobile')
                    ->label('Size of the name — phones')
                    ->options(Typography::brandSizeOptions())
                    ->helperText('Its own setting, because a phone gives the name about a quarter of the '
                        .'width a laptop does. Left automatic it steps down from the wide-screen size. A '
                        .'name too long for one line wraps to a second rather than being cut.'),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                $size = trim((string) ($data['brand_size'] ?? ''));
                $mobile = trim((string) ($data['brand_size_mobile'] ?? ''));

                $site->fill([
                    'font_display' => $data['font_display'] ?? null,
                    'font_body' => $data['font_body'] ?? null,
                    // Blank means "same as the body text" and "work it out from
                    // the name" — both are real answers, so both are stored as
                    // null rather than as a value somebody has to keep correct.
                    'brand_font' => filled($data['brand_font'] ?? null) ? $data['brand_font'] : null,
                    'brand_size' => $size === '' ? null : (int) $size,
                    'brand_size_mobile' => $mobile === '' ? null : (int) $mobile,
                ])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }
}

<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Sites\ActionButtons;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * Where each action button appears, and on which screen.
 *
 * Four actions and six places each, which sounds like a lot of switches until
 * you try to write the rule instead: WhatsApp is how this market answers and
 * is close to useless on a desktop; a button in the menu is redundant with
 * the band it points at and takes the width a long name needs; a strip at the
 * foot of the screen is right on a phone and clutter on a laptop. Every one
 * of those is a judgement about a particular business, so it is a switch.
 *
 * The form is one section per action: where it shows, and what it says. Nothing
 * here can produce a broken button — a placement for an action whose target
 * does not exist (no WhatsApp number, nothing to enquire about) simply does not
 * render. See App\Sites\Rendering\SiteActions.
 */
class EditActionButtonsAction
{
    private const DESCRIPTIONS = [
        'enquiry' => 'Points at the booking band, or at the enquiry form, or at the contact '
            .'details — whichever this site has. Leave the label empty to use the band\'s own heading.',
        'whatsapp' => 'Opens WhatsApp on the number under Contact. Shown only where there is one.',
        'call' => 'Dials the telephone number under Contact. Shown only where there is one.',
        'map' => 'Opens Google Maps on the coordinates or address under Location. Shown only where there is one.',
        'custom' => 'The business\'s own button. Empty link means the About band on this page.',
    ];

    private const TITLES = [
        'enquiry' => 'Enquiry button',
        'whatsapp' => 'WhatsApp button',
        'call' => 'Call button',
        'map' => 'Map button',
        'custom' => 'Your own button',
    ];

    private const PLACES = [
        'menu.phone' => 'Menu bar · phone',
        'menu.desktop' => 'Menu bar · desktop',
        'hero.phone' => 'Opening screen · phone',
        'hero.desktop' => 'Opening screen · desktop',
        'footer.phone' => 'Foot of the screen · phone',
        'footer.desktop' => 'Foot of the screen · desktop',
    ];

    public static function make(string $name = 'edit_action_buttons'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'edit_action_buttons'): PageAction
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
            ->label('Action buttons')
            ->icon('heroicon-o-cursor-arrow-rays')
            ->color('gray')
            ->modalHeading('Enquire, call, WhatsApp — and one of your own')
            ->modalDescription('Each button can be in the menu bar, on the opening screen, or on the strip '
                .'fixed to the foot of the screen — separately for a phone and for a desktop. A button '
                .'with nothing to point at is never shown, whatever is ticked here.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('2xl')
            ->fillForm(function (Listing|Partner|null $record): array {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return [];
                }

                $data = [];

                foreach (ActionButtons::normalise($site->action_buttons) as $key => $entry) {
                    $data[$key.'_places'] = $entry['places'];
                    $data[$key.'_label'] = $entry['label'];

                    if ($key === 'custom') {
                        $data['custom_href'] = $entry['href'];
                    }
                }

                return $data;
            })
            ->form(self::schema())
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                $payload = [];

                foreach (array_keys(ActionButtons::defaults()) as $key) {
                    $payload[$key] = [
                        'places' => array_values((array) ($data[$key.'_places'] ?? [])),
                        'label' => $data[$key.'_label'] ?? null,
                        'href' => $key === 'custom' ? ($data['custom_href'] ?? null) : null,
                    ];
                }

                // Normalised on the way in as well as on the way out, so what
                // is stored is what the renderer would read anyway.
                $site->forceFill(['action_buttons' => ActionButtons::normalise($payload)])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function schema(): array
    {
        $sections = [];

        foreach (array_keys(ActionButtons::defaults()) as $key) {
            $fields = [
                Forms\Components\CheckboxList::make($key.'_places')
                    ->label('Where it shows')
                    ->options(self::PLACES)
                    ->columns(2)
                    ->gridDirection('row')
                    ->bulkToggleable(),

                Forms\Components\TextInput::make($key.'_label')
                    ->label('Label')
                    ->maxLength(ActionButtons::MAX_LABEL)
                    ->placeholder(match ($key) {
                        'whatsapp' => 'WhatsApp',
                        'call' => 'Call',
                        'custom' => 'About us',
                        default => 'From the band it points at',
                    })
                    ->helperText('A button in a 64px bar, or one of three on a 375px screen — '
                        .'so this is a number of pixels as much as a word.'),
            ];

            if ($key === 'custom') {
                $fields[] = Forms\Components\TextInput::make('custom_href')
                    ->label('Link')
                    ->maxLength(2048)
                    ->placeholder('The About band on this page')
                    ->helperText('A full address, a page of this site (/rooms), or a band on this page (#s2).');
            }

            $sections[] = Forms\Components\Section::make(self::TITLES[$key])
                ->description(self::DESCRIPTIONS[$key])
                ->collapsible()
                ->schema($fields);
        }

        return $sections;
    }
}

<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Site;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * The three buttons a visitor can act on — book, call, WhatsApp — one switch
 * each.
 *
 * All three are on when a site is built, because a website nobody can act on
 * is a brochure. But each is a business decision that is not ours: a lodge
 * that takes no telephone bookings, an owner whose WhatsApp is their private
 * mobile, a property with nothing sellable that would rather not invite a
 * booking. So they are switches rather than a rule.
 *
 * Same shape as the other editors on the Website tab: one configure(), poured
 * into a form action or a page-header one.
 */
class EditActionButtonsAction
{
    public static function make(string $name = 'edit_action_buttons'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_action_buttons'): PageAction
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
            ->label('Action buttons')
            ->icon('heroicon-o-cursor-arrow-rays')
            ->color('gray')
            ->modalHeading('Book, call, WhatsApp')
            ->modalDescription('The three things a visitor is asked to do. They are in the bar, under the '
                .'opening headline, and on the strip fixed to the bottom of the screen on a phone. '
                .'Switching one off hides the button everywhere — the number itself stays in the '
                .'contact section and the footer.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return [];
                }

                return [
                    'show_book_button' => $site->show_book_button,
                    'show_call_button' => $site->show_call_button,
                    'show_whatsapp_button' => $site->show_whatsapp_button,
                ];
            })
            ->form(fn (?Listing $record): array => [
                Forms\Components\Toggle::make('show_book_button')
                    ->label('Booking button')
                    ->helperText('Points at the booking section, or at the enquiry form where there is '
                        .'nothing bookable yet.'),

                Forms\Components\Toggle::make('show_call_button')
                    ->label('Call button')
                    ->helperText(self::detailHint($record, 'contact_phone', 'No telephone number on the '
                        .'site yet, so nothing is shown either way.')),

                Forms\Components\Toggle::make('show_whatsapp_button')
                    ->label('WhatsApp button')
                    ->helperText(self::detailHint($record, 'whatsapp', 'No WhatsApp number on the site '
                        .'yet, so nothing is shown either way.')),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                $site->forceFill([
                    'show_book_button' => (bool) ($data['show_book_button'] ?? true),
                    'show_call_button' => (bool) ($data['show_call_button'] ?? true),
                    'show_whatsapp_button' => (bool) ($data['show_whatsapp_button'] ?? true),
                ])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    /**
     * A switch that is on for a number the site does not have shows nothing,
     * which reads as broken. Saying so beats leaving somebody to work out why.
     */
    private static function detailHint(?Listing $record, string $column, string $missing): ?string
    {
        $site = $record === null ? null : self::siteFor($record);

        return $site !== null && blank($site->{$column}) ? $missing : null;
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

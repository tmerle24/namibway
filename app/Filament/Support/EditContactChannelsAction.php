<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Site;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * The phone, WhatsApp and email that appear on the site and power its buttons.
 *
 * These are site-level fields rather than listing-level ones: a listing's
 * phone is the contact the platform holds, and a site's channel is what a
 * visitor presses. They can differ — an owner might have a dedicated WhatsApp
 * number for website enquiries — and merging them would lose that distinction.
 *
 * Same double-flavour shape as EditHeroAction: a FormAction for the admin's
 * WebsiteTab and a PageAction for the partner's page header.
 */
class EditContactChannelsAction
{
    public static function make(string $name = 'edit_contact_channels'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_contact_channels'): PageAction
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
            ->label('Contact channels')
            ->icon('heroicon-o-phone')
            ->color('gray')
            ->modalHeading('Contact channels')
            ->modalDescription('How a visitor can reach the business. WhatsApp and the call button link to these; the enquiry form sends here too.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $site = $record === null ? null : self::siteFor($record);

                return [
                    'whatsapp' => $site?->whatsapp,
                    'contact_phone' => $site?->contact_phone,
                    'contact_email' => $site?->contact_email,
                ];
            })
            ->form([
                Forms\Components\TextInput::make('whatsapp')
                    ->label('WhatsApp')
                    ->helperText('The number the WhatsApp button opens. International format (+264 81 …). Leave empty to hide the button.')
                    ->maxLength(50),

                Forms\Components\TextInput::make('contact_phone')
                    ->label('Phone')
                    ->tel()
                    ->helperText('The number the call button dials. Leave empty to hide it.')
                    ->maxLength(50),

                Forms\Components\TextInput::make('contact_email')
                    ->label('Email')
                    ->email()
                    ->helperText('Shown in the Contact block.')
                    ->maxLength(255),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                $site->fill([
                    'whatsapp' => filled($data['whatsapp']) ? trim($data['whatsapp']) : null,
                    'contact_phone' => filled($data['contact_phone']) ? trim($data['contact_phone']) : null,
                    'contact_email' => filled($data['contact_email']) ? trim($data['contact_email']) : null,
                ])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}

<?php

namespace App\Filament\Support;

use App\Mail\SiteTermsConfirmation;
use App\Models\Listing;
use App\Models\Partner;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

/**
 * Ask the business to confirm its own website, by email.
 *
 * Admin only, and deliberately so: this is us handing a customer the thing they
 * agree to, and the customer is the one who receives it. The checkbox on the
 * publish action stays for the case it was written for — somebody confirming on
 * the telephone while we are on the call — but this is the path that records
 * what they did rather than what we were told.
 */
class SendTermsConfirmationAction
{
    public static function make(string $name = 'send_terms_confirmation'): FormAction
    {
        return FormAction::make($name)
            ->label('Ask the business to confirm')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null
                && SiteResolver::for($record) !== null
                && SiteResolver::for($record)->terms_accepted_at === null)
            ->requiresConfirmation()
            ->modalHeading('Send them the website to confirm')
            ->modalDescription('They get a link to the site, the privacy and legal notices, our own '
                .'terms where one is published, and a button that publishes it under their name.')
            ->modalSubmitActionLabel('Send it')
            ->action(function (Listing|Partner|null $record): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                // $record cannot be null here: $site was resolved from it above.
                $fallback = $record instanceof Listing
                    ? $record->partner?->email
                    : $record->email;

                $to = $site->contact_email ?: $fallback;

                if (blank($to)) {
                    Notification::make()
                        ->title('No address to send to')
                        ->body('This site has no contact email, and neither has the listing or its '
                            .'partner. Add one first.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Mail::to((string) $to)->send(new SiteTermsConfirmation($site));

                Notification::make()
                    ->title('Sent to '.$to)
                    ->body('The site publishes itself the moment they confirm.')
                    ->success()
                    ->send();
            });
    }
}

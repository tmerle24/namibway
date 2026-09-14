<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Sites\Rendering\BusinessCard;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Actions\Action as FormAction;

/**
 * The digital business card and the QR code for the printed one.
 *
 * Nothing to fill in: the card is the site's own contact details, so this
 * hands over the address, shows the code and gives it as a file for the
 * printer. Available to the owner as well as to us — it is their card.
 */
class SiteCardAction
{
    public static function make(string $name = 'business_card'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'business_card'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => SiteResolver::for($record) !== null);
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
            ->label('Business card')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading('Digital business card')
            ->modalDescription('One page with call, WhatsApp, email, save-contact, the website and the enquiry '
                .'form — opened by scanning the code on a printed card.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (Listing|Partner|null $record) {
                $site = $record === null ? null : SiteResolver::for($record);

                return $site instanceof Site
                    ? view('filament.site-card', ['site' => $site, 'card' => BusinessCard::for($site)])
                    : null;
            });
    }
}

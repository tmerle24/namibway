<?php

namespace App\Filament\Support;

use App\Enums\SubscriptionStatus;
use App\Mail\WebsiteOrdered;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\WebsiteSubscription;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Mail;

/**
 * The owner ordering a website.
 *
 * The button beside it — CreateWebsiteAction — has been switched off since the
 * day it was written, with a tooltip saying to talk to us. That is a dead end
 * on a screen: it tells somebody the product exists and gives them nothing to
 * press. This is the something.
 *
 * **An order is a request, not a purchase.** There is no payment provider for
 * this market, billing runs by invoice, and a person at NamibWay decides they
 * are a customer. So this creates a subscription in `requested`, tells us, and
 * says so plainly to the owner rather than implying anything has been bought.
 */
class OrderWebsiteAction
{
    public static function header(string $name = 'order_website'): PageAction
    {
        return PageAction::make($name)
            ->label('Order a website')
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->visible(fn (?Listing $record): bool => self::subscriptionFor($record) === null)
            ->modalHeading('Order a website for this business')
            ->modalDescription('We build it from this listing — its text, its photographs, its address '
                .'— and send it to you to look at before anything goes online. Nothing is charged here: '
                .'we invoice you, and the website switches on when the subscription starts.')
            ->modalSubmitActionLabel('Send the order')
            ->form([
                Textarea::make('note')
                    ->label('Anything we should know')
                    ->helperText('Optional. A domain you already own, a deadline, what matters most.')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (?Listing $record, array $data): void {
                $partner = self::partnerFor($record);

                if ($partner === null) {
                    Notification::make()
                        ->title('Not sent')
                        ->body('This listing has no business attached to it, so there is nobody to '
                            .'subscribe. Talk to us and we will sort it out.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt;
                }

                $note = trim((string) ($data['note'] ?? ''));

                $subscription = WebsiteSubscription::request($partner, $note === '' ? null : $note);

                // Only the first order is worth an email. Pressing the button
                // twice is somebody checking it worked, not a second customer.
                if ($subscription->wasRecentlyCreated) {
                    Mail::to((string) config('booking.team_address', 'team@namibway.com'))
                        ->send(new WebsiteOrdered($subscription));
                }

                Notification::make()
                    ->title($subscription->status === SubscriptionStatus::Requested
                        ? 'Ordered — we will be in touch'
                        : 'You already have a subscription')
                    ->body($subscription->status === SubscriptionStatus::Requested
                        ? 'We invoice you and switch the website on. Nothing has been charged.'
                        : 'Its state is "'.$subscription->status->label().'". Write to us if that looks wrong.')
                    ->success()
                    ->send();
            });
    }

    private static function partnerFor(?Listing $listing): ?Partner
    {
        return $listing?->partner;
    }

    private static function subscriptionFor(?Listing $listing): ?WebsiteSubscription
    {
        $partner = self::partnerFor($listing);

        return $partner === null ? null : $partner->websiteSubscription;
    }
}

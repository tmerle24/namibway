<?php

namespace App\Filament\Partner\Support;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Services\Booking\InquiryDecisionService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;

/**
 * Answering a booking request from inside the panel.
 *
 * The same three decisions the emailed buttons offer — confirm and ask for the
 * deposit, confirm without asking, decline — because a property that has the
 * panel open should not have to go and find the email. Same service underneath
 * (`InquiryDecisionService`), so the guest gets the same mail and the stay is
 * promoted the same way whichever surface was used.
 *
 * Filament separates table actions from page actions, so the flavour is chosen
 * here and the behaviour lives in one place below.
 */
class InquiryDecisions
{
    /** @return array<int, Tables\Actions\Action> */
    public static function tableActions(): array
    {
        return [
            Tables\Actions\Action::make('confirmWithPayment')
                ->label('Confirm & ask for deposit')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(fn (Inquiry $record): bool => self::isOpen($record))
                ->modalHeading('Confirm and ask for the deposit')
                ->modalDescription(self::description())
                ->modalSubmitActionLabel('Confirm and send')
                ->form([self::messageField()])
                ->action(fn (Inquiry $record, array $data) => self::confirmWithPayment($record, $data)),

            Tables\Actions\Action::make('confirm')
                ->label('Confirm')
                ->icon('heroicon-m-check')
                ->color('gray')
                ->visible(fn (Inquiry $record): bool => self::isOpen($record))
                ->requiresConfirmation()
                ->modalDescription('The guest is told the booking is confirmed. No payment is asked for.')
                ->action(fn (Inquiry $record) => self::confirm($record)),

            Tables\Actions\Action::make('decline')
                ->label('Decline')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->visible(fn (Inquiry $record): bool => self::isOpen($record))
                ->requiresConfirmation()
                ->modalDescription('The request is cancelled and the room goes back on sale.')
                ->action(fn (Inquiry $record) => self::decline($record)),
        ];
    }

    /**
     * The same three on a record page, where the record comes from the page
     * rather than from the row.
     *
     * @return array<int, Actions\Action>
     */
    public static function pageActions(): array
    {
        return [
            Actions\Action::make('confirmWithPayment')
                ->label('Confirm & ask for deposit')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(fn (?Inquiry $record): bool => $record !== null && self::isOpen($record))
                ->modalHeading('Confirm and ask for the deposit')
                ->modalDescription(self::description())
                ->modalSubmitActionLabel('Confirm and send')
                ->form([self::messageField()])
                ->action(fn (?Inquiry $record, array $data) => $record === null ? null : self::confirmWithPayment($record, $data)),

            Actions\Action::make('confirm')
                ->label('Confirm')
                ->icon('heroicon-m-check')
                ->color('gray')
                ->visible(fn (?Inquiry $record): bool => $record !== null && self::isOpen($record))
                ->requiresConfirmation()
                ->modalDescription('The guest is told the booking is confirmed. No payment is asked for.')
                ->action(fn (?Inquiry $record) => $record === null ? null : self::confirm($record)),

            Actions\Action::make('decline')
                ->label('Decline')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->visible(fn (?Inquiry $record): bool => $record !== null && self::isOpen($record))
                ->requiresConfirmation()
                ->modalDescription('The request is cancelled and the room goes back on sale.')
                ->action(fn (?Inquiry $record) => $record === null ? null : self::decline($record)),
        ];
    }

    private static function isOpen(Inquiry $inquiry): bool
    {
        return $inquiry->status === InquiryStatus::OnRequest;
    }

    private static function description(): string
    {
        return 'The guest gets one email: the confirmation, your message, and a button to pay the deposit.';
    }

    private static function messageField(): Textarea
    {
        return Textarea::make('message')
            ->label('A message for the guest')
            ->helperText('Optional. It goes out above the payment button, as your words.')
            ->rows(4)
            ->maxLength(2000);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function confirmWithPayment(Inquiry $inquiry, array $data): void
    {
        $message = trim((string) ($data['message'] ?? ''));

        $outcome = app(InquiryDecisionService::class)->confirmAndRequestDeposit(
            $inquiry,
            $message === '' ? null : $message,
        );

        if (! $outcome->handled) {
            self::alreadyHandled();

            return;
        }

        // Confirmed either way. Whether the payment link went with it is the
        // part the person pressing the button cannot see for themselves, so it
        // is the part the notification is about.
        if ($outcome->askedForPayment()) {
            Notification::make()
                ->title('Confirmed, and the guest has the payment link')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Confirmed, but no payment link went out')
            ->body($outcome->problem ?? '')
            ->warning()
            ->persistent()
            ->send();
    }

    private static function confirm(Inquiry $inquiry): void
    {
        if (! app(InquiryDecisionService::class)->confirm($inquiry)) {
            self::alreadyHandled();

            return;
        }

        Notification::make()->title('Confirmed. The guest has been told.')->success()->send();
    }

    private static function decline(Inquiry $inquiry): void
    {
        if (! app(InquiryDecisionService::class)->decline($inquiry)) {
            self::alreadyHandled();

            return;
        }

        Notification::make()->title('Declined. The request is cancelled.')->success()->send();
    }

    private static function alreadyHandled(): void
    {
        Notification::make()
            ->title('Already answered')
            ->body('Somebody has already handled this request — from the email, perhaps.')
            ->warning()
            ->send();
    }
}

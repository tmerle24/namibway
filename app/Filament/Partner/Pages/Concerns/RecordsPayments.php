<?php

namespace App\Filament\Partner\Pages\Concerns;

use App\Enums\PaymentCollector;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Mail\GuestPaymentRequest;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Payments\DTOs\FolioStatement;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\Folio;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentRecorder;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Throwable;

/**
 * Taking money at the desk, and saying what a stay still owes.
 *
 * Every write goes through PaymentRecorder. Nothing in this trait touches the
 * payments table, the Payment model or the folio columns — a Filament action
 * reaching into the tables is exactly what the single write path and its
 * architecture test exist to prevent, and the temptation arrives with the
 * first screen that can write.
 *
 * A refund is offered as its own button rather than as "enter a negative
 * number", because a desk that has to type a minus sign to give money back
 * will one day type it by accident on a payment.
 */
trait RecordsPayments
{
    /** Which payment a row-level action is about, set by the click. */
    public ?int $selectedPaymentId = null;

    /** Why there is no payment link, when there is none. Set by depositLink(). */
    protected ?string $depositRefusal = null;

    /** The stay the payment screens are about — the drawer's selection. */
    abstract public function selectedReservation(): ?Reservation;

    /** The property the page is showing — see SelectedProperty. */
    abstract protected function property(): ?Listing;

    /**
     * The statement behind the folio block in the drawer. Null when no stay is
     * open, which is what keeps the block off the screen entirely.
     */
    public function folio(): ?FolioStatement
    {
        $reservation = $this->selectedReservation();

        return $reservation === null ? null : app(Folio::class)->for($reservation);
    }

    public function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record a payment')
            ->icon('heroicon-m-banknotes')
            ->color('primary')
            ->modalHeading('Record a payment')
            ->modalDescription('Money received against this stay — cash at the desk, a card, a transfer.')
            ->modalSubmitActionLabel('Record it')
            ->fillForm(fn (): array => $this->paymentPrefill())
            ->form(fn (): array => $this->paymentForm(refund: false))
            ->action(fn (array $data) => $this->writePayment($data, refund: false));
    }

    /**
     * Ask the guest for the deposit through a payment provider.
     *
     * The link is shown *and* can be sent from here. It used to be shown only,
     * on the reasoning that who sends it and with what words is a conversation
     * the property is already having — which is right about the words and wrong
     * about the sending, since it left every desk copying a URL into their own
     * mail client. The words are the point of the message box; nothing goes out
     * until the button is pressed.
     */
    public function requestDepositAction(): Action
    {
        return Action::make('requestDeposit')
            ->label('Ask for the deposit')
            ->icon('heroicon-m-link')
            ->color('gray')
            ->modalHeading('Payment link for this booking')
            ->modalSubmitActionLabel('Email it to the guest')
            ->modalCancelActionLabel('Close')
            ->form(fn (): array => [
                Placeholder::make('link')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->depositLinkHtml()),

                Textarea::make('message')
                    ->label('A message for the guest')
                    ->helperText('Optional. It goes out above the payment button, as your words.')
                    ->rows(4)
                    ->maxLength(2000)
                    ->visible(fn (): bool => $this->depositLink() !== null),
            ])
            ->action(fn (array $data) => $this->emailDepositLink($data['message'] ?? null));
    }

    /**
     * The link to ask this stay's guest for a deposit, or null with the reason
     * kept for the screen.
     *
     * Creates the attempt if there is not already an open one. Reusing an open
     * attempt matters: a guest who is sent two links has two ways to pay the
     * same deposit, and the property reconciles the mess.
     */
    private function depositLink(): ?string
    {
        $reservation = $this->selectedReservation();

        if ($reservation === null) {
            return null;
        }

        try {
            return app(PaymentGateway::class)->linkFor($reservation, PaymentPurpose::Deposit);
        } catch (InvalidArgumentException $refusal) {
            $this->depositRefusal = $refusal->getMessage();

            return null;
        }
    }

    private function depositLinkHtml(): HtmlString
    {
        $url = $this->depositLink();

        if ($url === null) {
            return new HtmlString('<p style="color: rgb(185 28 28);">'.e((string) $this->depositRefusal).'</p>');
        }

        $intent = $this->openDepositIntent();

        return new HtmlString(
            ($intent === null ? '' : '<p style="margin-bottom: .5rem;">'
                .e(Money::format($intent->amount, $intent->currency)).' — '
                .e($intent->purpose->label()).', '.e($intent->status->label()).'.'
                .'</p>')
            .'<p style="word-break: break-all;"><a href="'.e($url).'" target="_blank" rel="noopener">'.e($url).'</a></p>'
            .'<p style="opacity: .7; margin-top: .5rem;">Send it below, or copy it into a message of your own. '
            .'Either way it records the payment against this stay automatically.</p>'
        );
    }

    /** The attempt the link above belongs to, for the amount shown beside it. */
    private function openDepositIntent(): ?PaymentIntent
    {
        $reservation = $this->selectedReservation();

        if ($reservation === null) {
            return null;
        }

        return PaymentIntent::query()
            ->where('reservation_id', $reservation->id)
            ->where('purpose', PaymentPurpose::Deposit->value)
            ->where('status', PaymentIntentStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    private function emailDepositLink(?string $message): void
    {
        $reservation = $this->selectedReservation();
        $url = $this->depositLink();
        $intent = $this->openDepositIntent();

        if ($reservation === null || $url === null || $intent === null) {
            Notification::make()
                ->title('Nothing sent')
                ->body($this->depositRefusal ?? 'There is no payment link to send.')
                ->danger()
                ->send();

            return;
        }

        // A stay taken at the desk may genuinely have no email address — the
        // field is optional there on purpose. Say so rather than failing.
        if (blank($reservation->guest_email)) {
            Notification::make()
                ->title('No email address for this guest')
                ->body('Copy the link above and send it however you are in touch with them.')
                ->warning()
                ->send();

            return;
        }

        Mail::to((string) $reservation->guest_email)->send(new GuestPaymentRequest(
            $intent,
            $url,
            filled($message) ? trim((string) $message) : null,
        ));

        Notification::make()
            ->title('Sent to '.$reservation->guest_email)
            ->body(Money::format($intent->amount, $intent->currency).' — '.strtolower($intent->purpose->label()).'.')
            ->success()
            ->send();
    }

    public function recordRefundAction(): Action
    {
        return Action::make('recordRefund')
            ->label('Record a refund')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('gray')
            ->modalHeading('Record a refund')
            ->modalDescription('Money given back to the guest. It stays on the folio as its own line — nothing is deleted.')
            ->modalSubmitActionLabel('Record the refund')
            ->fillForm(fn (): array => $this->paymentPrefill())
            ->form(fn (): array => $this->paymentForm(refund: true))
            ->action(fn (array $data) => $this->writePayment($data, refund: true));
    }

    /**
     * Undo a row that should not have been entered.
     *
     * Not a refund, and worth the second button: a refund is money genuinely
     * handed back, a reversal says the line was a mistake. An accountant asked
     * "did we return that, or never have it?" needs the two to look different
     * a year later.
     */
    public function reversePaymentAction(): Action
    {
        return Action::make('reversePayment')
            ->label('Correct')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->modalHeading('Correct this payment')
            ->modalDescription('The line stays and a matching correction is added beside it. Enter the right payment afterwards.')
            ->modalSubmitActionLabel('Correct it')
            ->form([
                Textarea::make('reason')
                    ->label('What was wrong?')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Entered against the wrong stay, wrong amount typed …'),
            ])
            ->action(function (array $data): void {
                $payment = $this->requireSelectedPayment();

                try {
                    app(PaymentRecorder::class)->reverse(
                        $payment,
                        $data['reason'] ?? null,
                        $this->currentPaymentUserId(),
                    );
                } catch (InvalidArgumentException $refusal) {
                    $this->refusePayment('That could not be corrected', $refusal->getMessage());
                }

                Notification::make()->success()->title('Corrected')->send();
            });
    }

    /** The late fact: the transfer landed. */
    public function clearPaymentAction(): Action
    {
        return Action::make('clearPayment')
            ->label('Mark as received')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('The money has arrived')
            ->modalDescription('Use this once the transfer shows on the bank statement.')
            ->action(function (): void {
                $payment = $this->requireSelectedPayment();

                try {
                    app(PaymentRecorder::class)->markCleared($payment);
                } catch (InvalidArgumentException $refusal) {
                    $this->refusePayment('That could not be changed', $refusal->getMessage());
                }

                Notification::make()->success()->title('Marked as received')->send();
            });
    }

    /** The other late fact: it never came. */
    public function failPaymentAction(): Action
    {
        return Action::make('failPayment')
            ->label('It never arrived')
            ->icon('heroicon-m-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('This payment never arrived')
            ->modalDescription('The line stays as the record that it was expected. The stay goes back to owing the money.')
            ->action(function (): void {
                $payment = $this->requireSelectedPayment();

                try {
                    app(PaymentRecorder::class)->markFailed($payment);
                } catch (InvalidArgumentException $refusal) {
                    $this->refusePayment('That could not be changed', $refusal->getMessage());
                }

                Notification::make()->success()->title('Recorded as not arrived')->send();
            });
    }

    /** Which line the row buttons act on. Resolved through the open stay. */
    public function selectPayment(int $paymentId): void
    {
        $reservation = $this->selectedReservation();

        $this->selectedPaymentId = $reservation === null
            ? null
            : Payment::query()
                ->where('reservation_id', $reservation->id)
                ->whereKey($paymentId)
                ->value('id');
    }

    /*
    |--------------------------------------------------------------------------
    | The form
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function paymentPrefill(): array
    {
        $statement = $this->folio();
        $balance = $statement?->balance();

        return [
            // The balance, because that is the payment a desk takes nine
            // times out of ten. Negative — an overpaid stay — prefills
            // nothing rather than a number that would be wrong in both
            // directions.
            'amount' => $balance !== null && $balance > 0 ? number_format($balance, 2, '.', '') : null,
            'method' => PaymentMethod::Cash->value,
            'received_at' => Carbon::now()->toDateString(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function paymentForm(bool $refund): array
    {
        $currency = $this->folioCurrency();

        return [
            Grid::make(2)->schema([
                TextInput::make('amount')
                    ->label($refund ? 'Amount to refund' : 'Amount')
                    ->numeric()
                    // Always a positive number on screen. The sign is the
                    // button's job, not the person's.
                    ->minValue(0.01)
                    ->required()
                    ->prefix(Money::symbol($currency))
                    ->helperText($refund ? null : $this->balanceHint()),
                Select::make('method')
                    ->label('How')
                    ->options(collect(PaymentMethod::cases())
                        ->mapWithKeys(fn (PaymentMethod $method) => [$method->value => $method->label()])
                        ->all())
                    ->default(PaymentMethod::Cash->value)
                    ->required()
                    ->live(),
                DatePicker::make('received_at')
                    ->label($refund ? 'Date refunded' : 'Date received')
                    ->native(false)
                    ->displayFormat('D, d M Y')
                    ->required()
                    // A transfer is entered on Thursday for a Tuesday
                    // movement, so the date is asked rather than assumed.
                    ->helperText('When the money moved, not when you are typing this.'),
                TextInput::make('reference')
                    ->label('Reference')
                    ->maxLength(120)
                    ->placeholder('Receipt number, EFT reference …'),
            ]),

            // Folded away because most guests pay in the property's own
            // currency, and three fields that are empty nine times out of ten
            // cost a screenful every other time the form is open.
            Section::make('Paid in another currency')
                ->description('Only if the guest handed over something other than '.strtoupper($currency).'.')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextInput::make('received_amount')
                        ->label('Amount handed over')
                        ->numeric()
                        ->minValue(0.01)
                        ->live(onBlur: true),
                    TextInput::make('received_currency')
                        ->label('Currency')
                        ->maxLength(3)
                        ->placeholder('EUR')
                        ->live(onBlur: true)
                        // All three or none — a half-recorded conversion
                        // cannot be reconciled later, so the form asks for the
                        // set rather than letting the recorder refuse it after
                        // the modal has closed.
                        ->required(fn (Get $get): bool => $this->conversionStarted($get)),
                    TextInput::make('exchange_rate')
                        ->label('Rate used')
                        ->numeric()
                        ->minValue(0.00000001)
                        ->helperText(strtoupper($currency).' per 1 unit')
                        ->required(fn (Get $get): bool => $this->conversionStarted($get)),
                ]),

            Textarea::make('note')
                ->label('Note')
                ->rows(2)
                ->maxLength(500)
                ->placeholder($refund ? 'Why the money was returned.' : 'Anything the next person should know.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writePayment(array $data, bool $refund): void
    {
        $reservation = $this->selectedReservation();

        if ($reservation === null) {
            return;
        }

        $amount = abs((float) ($data['amount'] ?? 0));
        $received = filled($data['received_amount'] ?? null) ? abs((float) $data['received_amount']) : null;

        try {
            app(PaymentRecorder::class)->record(new PaymentRequest(
                reservation: $reservation,
                amount: $refund ? -$amount : $amount,
                method: PaymentMethod::from((string) $data['method']),
                // Money taken at a desk is the property's. What NamibWay
                // collects arrives through a provider and records itself —
                // see slice 5 — so this screen never has to ask.
                collectedBy: PaymentCollector::Partner,
                receivedAt: $this->parsePaymentDate($data['received_at'] ?? null),
                receivedAmount: $received === null ? null : ($refund ? -$received : $received),
                receivedCurrency: $data['received_currency'] ?? null,
                exchangeRate: filled($data['exchange_rate'] ?? null) ? (float) $data['exchange_rate'] : null,
                reference: $data['reference'] ?? null,
                recordedBy: $this->currentPaymentUserId(),
                note: $data['note'] ?? null,
            ));
        } catch (InvalidArgumentException $refusal) {
            $this->refusePayment(
                $refund ? 'The refund was not recorded' : 'The payment was not recorded',
                $refusal->getMessage(),
            );
        }

        $statement = app(Folio::class)->for($reservation->refresh());

        Notification::make()
            ->success()
            ->title($refund ? 'Refund recorded' : 'Payment recorded')
            ->body($this->settlementLine($statement))
            ->send();
    }

    /**
     * What the desk reads out to the guest afterwards. The balance is the
     * point of the whole screen, so it is said at the moment the money
     * changes hands rather than only in a table below.
     */
    private function settlementLine(FolioStatement $statement): string
    {
        $balance = $statement->balance();

        if ($balance === null) {
            return 'This stay has no price yet, so there is nothing to be square with.';
        }

        if (Money::cents($balance) === 0) {
            return 'Paid in full.';
        }

        return $balance > 0
            ? Money::format($balance, $statement->currency()).' still to pay.'
            : Money::format(abs($balance), $statement->currency()).' owed back to the guest.';
    }

    private function balanceHint(): ?string
    {
        $statement = $this->folio();
        $balance = $statement?->balance();

        if ($statement === null || $balance === null || Money::cents($balance) <= 0) {
            return null;
        }

        return Money::format($balance, $statement->currency()).' outstanding.';
    }

    private function conversionStarted(Get $get): bool
    {
        return filled($get('received_amount'))
            || filled($get('received_currency'))
            || filled($get('exchange_rate'));
    }

    /**
     * The currency the form's amounts are in.
     *
     * The stay's own, because that is what the folio is denominated in — the
     * property's selling currency is only the fallback for a form opened with
     * no stay behind it, which the drawer does not do but a future screen
     * might.
     */
    private function folioCurrency(): string
    {
        $reservation = $this->selectedReservation();

        if ($reservation instanceof Reservation) {
            return $reservation->currency;
        }

        return $this->property()?->sellingCurrency() ?? 'NAD';
    }

    private function requireSelectedPayment(): Payment
    {
        $reservation = $this->selectedReservation();

        $payment = $reservation === null || $this->selectedPaymentId === null
            ? null
            : Payment::query()
                ->where('reservation_id', $reservation->id)
                ->find($this->selectedPaymentId);

        if (! $payment instanceof Payment) {
            $this->refusePayment('That payment could not be found', 'Close the stay and open it again.');
        }

        return $payment;
    }

    /**
     * Report a refusal and stop, leaving the modal open with the reason on
     * screen rather than closing it over a half-done action.
     *
     * @throws Halt
     */
    private function refusePayment(string $title, string $reason): never
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($reason)
            ->persistent()
            ->send();

        throw new Halt;
    }

    private function parsePaymentDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** auth()->id() widens to int|string|null; the ledger wants int|null. */
    private function currentPaymentUserId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}

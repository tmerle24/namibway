<?php

namespace App\Filament\Partner\Pages\Concerns;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Services\Payments\InvoiceIssuer;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Raising the guest's invoice from the stay drawer, and correcting one that
 * was wrong.
 *
 * Every write goes through InvoiceIssuer. Nothing here touches the invoices
 * table or the numbering counter — the gapless guarantee lives in one
 * transaction in one class, and a screen that took its own number would break
 * it on the first busy morning.
 *
 * The correction path is deliberately not called "edit". There is none: an
 * issued invoice cannot be changed, so the button says what actually happens
 * — a credit note is raised and a fresh invoice can then be issued.
 */
trait IssuesInvoices
{
    /** Which invoice a row-level action is about, set by the click. */
    public ?int $selectedInvoiceId = null;

    /** The stay the invoice screens are about — the drawer's selection. */
    abstract public function selectedReservation(): ?Reservation;

    /**
     * What has been issued against the open stay, newest first.
     *
     * @return Collection<int, Invoice>
     */
    public function stayInvoices(): Collection
    {
        $reservation = $this->selectedReservation();

        /** @var Collection<int, Invoice> $empty */
        $empty = new Collection;

        if ($reservation === null) {
            return $empty;
        }

        return Invoice::query()
            ->where('reservation_id', $reservation->id)
            ->with('creditNote')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();
    }

    public function issueInvoiceAction(): Action
    {
        return Action::make('issueInvoice')
            ->label('Issue an invoice')
            ->icon('heroicon-m-document-text')
            ->color('gray')
            ->modalHeading('Issue an invoice for this stay')
            ->modalDescription('It takes the next number in your series and cannot be changed afterwards — a mistake is corrected with a credit note.')
            ->modalSubmitActionLabel('Issue it')
            ->form([
                Placeholder::make('what')
                    ->label('What it will say')
                    ->content(fn (): string => $this->invoicePreview()),
            ])
            ->action(function (): void {
                $reservation = $this->selectedReservation();

                if ($reservation === null) {
                    return;
                }

                try {
                    $invoice = app(InvoiceIssuer::class)->issueForStay(
                        $reservation,
                        issuedBy: $this->currentInvoiceUserId(),
                    );
                } catch (InvalidArgumentException $refusal) {
                    $this->refuseInvoice('The invoice was not issued', $refusal->getMessage());
                }

                Notification::make()
                    ->success()
                    ->title('Invoice issued')
                    ->body($invoice->number)
                    ->send();
            });
    }

    /** Which invoice the row buttons act on. Resolved through the open stay. */
    public function selectInvoice(int $invoiceId): void
    {
        $reservation = $this->selectedReservation();

        $this->selectedInvoiceId = $reservation === null
            ? null
            : Invoice::query()
                ->where('reservation_id', $reservation->id)
                ->whereKey($invoiceId)
                ->value('id');
    }

    public function creditInvoiceAction(): Action
    {
        return Action::make('creditInvoice')
            ->label('Credit it')
            ->icon('heroicon-m-receipt-refund')
            ->color('danger')
            ->modalHeading('Credit this invoice')
            ->modalDescription('A credit note is issued against it with its own number. The original stays exactly as it was sent.')
            ->modalSubmitActionLabel('Issue the credit note')
            ->form([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Wrong dates, wrong guest, priced incorrectly …'),
            ])
            ->action(function (array $data): void {
                $invoice = $this->requireSelectedInvoice();

                try {
                    $credit = app(InvoiceIssuer::class)->creditNoteFor(
                        $invoice,
                        $data['reason'] ?? null,
                        $this->currentInvoiceUserId(),
                    );
                } catch (InvalidArgumentException $refusal) {
                    $this->refuseInvoice('That could not be credited', $refusal->getMessage());
                }

                Notification::make()
                    ->success()
                    ->title('Credit note issued')
                    ->body($credit->number.' — issue a corrected invoice if one is owed.')
                    ->send();
            });
    }

    /**
     * The totals the document will carry, shown before it is issued.
     *
     * An invoice cannot be taken back once it has a number, so the one thing
     * this modal owes somebody is a chance to notice the stay is not priced
     * the way they expected.
     */
    private function invoicePreview(): string
    {
        $reservation = $this->selectedReservation();

        if ($reservation === null) {
            return '';
        }

        if ($reservation->total_amount === null) {
            return 'This stay has no price yet. Price it before invoicing.';
        }

        return Money::format($reservation->total_amount, $reservation->currency)
            .' for '.$reservation->guest_name
            .' — '.$reservation->check_in->isoFormat('D MMM YYYY')
            .' to '.$reservation->check_out->isoFormat('D MMM YYYY').'.';
    }

    private function requireSelectedInvoice(): Invoice
    {
        $reservation = $this->selectedReservation();

        $invoice = $reservation === null || $this->selectedInvoiceId === null
            ? null
            : Invoice::query()
                ->where('reservation_id', $reservation->id)
                ->find($this->selectedInvoiceId);

        if (! $invoice instanceof Invoice) {
            $this->refuseInvoice('That invoice could not be found', 'Close the stay and open it again.');
        }

        return $invoice;
    }

    /**
     * @throws Halt
     */
    private function refuseInvoice(string $title, string $reason): never
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($reason)
            ->persistent()
            ->send();

        throw new Halt;
    }

    /** auth()->id() widens to int|string|null; the ledger wants int|null. */
    private function currentInvoiceUserId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}

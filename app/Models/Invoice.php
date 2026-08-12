<?php

namespace App\Models;

use App\Enums\InvoiceIssuer;
use App\Enums\InvoiceKind;
use App\Exceptions\Payments\IssuedInvoiceException;
use App\Models\Concerns\GuardsMoneyWrites;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A legal document. See the invoices migration for the whole design; the two
 * things enforced *here* are that it cannot be changed and that reading it
 * never means recomputing it.
 *
 * @property int $id
 * @property string $number
 * @property string $series
 * @property int $year
 * @property int $sequence_number
 * @property CarbonImmutable $issued_at
 * @property InvoiceIssuer $issuer
 * @property string $issuer_name
 * @property InvoiceKind $kind
 * @property int|null $reservation_id
 * @property int|null $partner_id
 * @property int|null $corrects_invoice_id
 * @property string $currency
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $total
 * @property array<int, array<string, mixed>> $lines
 * @property string $bill_to_name
 * @property string|null $bill_to_email
 * @property string|null $bill_to_address
 * @property int|null $issued_by
 */
class Invoice extends Model
{
    use GuardsMoneyWrites;

    protected $fillable = [
        'number',
        'series',
        'year',
        'sequence_number',
        'issued_at',
        'issuer',
        'issuer_name',
        'kind',
        'reservation_id',
        'partner_id',
        'corrects_invoice_id',
        'currency',
        'subtotal',
        'tax_amount',
        'total',
        'lines',
        'bill_to_name',
        'bill_to_email',
        'bill_to_address',
        'issued_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'issuer' => InvoiceIssuer::class,
        'kind' => InvoiceKind::class,
        'year' => 'integer',
        'sequence_number' => 'integer',
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'total' => 'float',
        'lines' => 'array',
    ];

    /**
     * Immutability, enforced rather than agreed.
     *
     * The single most important line in this file. An issued invoice that can
     * be edited is not a legal document — it is a draft with a number on it,
     * and every guarantee built on top of it (gapless numbering, a credit note
     * that means something, a VAT return that reconciles) is a guarantee that
     * quietly is not kept.
     *
     * Loud rather than silent: a refusal that throws is a bug report at the
     * moment the mistake is made. Silently discarding the change would leave
     * somebody believing they had corrected an invoice.
     *
     * `booted()` and not `bootInvoice()`: Laravel's boot{Name} convention is
     * for *traits* only, so a method named after the model is never called and
     * the guarantee would be silently absent. Its own test now says so.
     */
    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            throw new IssuedInvoiceException($invoice->number);
        });

        static::deleting(function (self $invoice): void {
            throw new IssuedInvoiceException($invoice->number);
        });
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * The invoice this credit note corrects.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'corrects_invoice_id');
    }

    /**
     * The credit note that corrects this invoice, if one has been raised.
     * HasOne rather than HasMany because the unique index says there is at
     * most one.
     *
     * @return HasOne<Invoice, $this>
     */
    public function creditNote(): HasOne
    {
        return $this->hasOne(Invoice::class, 'corrects_invoice_id');
    }

    /**
     * Who pressed the button. Not `issuer()` — that word is already the
     * `issuer` column, meaning which *business* the document comes from, and
     * two meanings for one name on one model is how the wrong one gets read.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isCancelled(): bool
    {
        return $this->creditNote()->exists();
    }

    /**
     * What this document still stands for, after any credit note against it.
     *
     * Zero for a fully credited invoice — which is what "the pair nets to the
     * corrected amount" means when read from the original's side.
     */
    public function netTotal(): float
    {
        $credited = (float) ($this->creditNote()->value('total') ?? 0.0);

        return Money::fromCents(Money::cents($this->total) + Money::cents($credited));
    }

    /**
     * The document as it was issued. Never recomputed from the stay.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lineItems(): array
    {
        return $this->lines;
    }
}

<?php

namespace App\Enums;

/**
 * How the money between a guest, a property and us is arranged.
 *
 * The industry's three, not ours — Booking.com runs the first, Expedia the
 * second, most DMCs and specialist agents the third. That matters twice: a
 * lodge owner has probably met them before, and per CLAUDE.md the industry's
 * names are the names we use.
 *
 * ## It is not a setting
 *
 * There is no `settlement_model` column anywhere and there must not be one.
 * The model is **derived from the deposit share** — see forDepositRate() — for
 * a reason worth restating whenever somebody is tempted to add the column:
 * the three models differ in exactly one thing, *what share of the folio we
 * collect*, and storing that twice makes it possible to configure a partner
 * into "merchant model, we collect nothing", which is not a thing.
 *
 * One number, three behaviours, no way to contradict yourself.
 */
enum SettlementModel: string
{
    /**
     * The property is the merchant. The guest pays them directly, and we
     * invoice the partner for our commission afterwards.
     *
     * The only model with **collection risk on our side**, which is why 0 %
     * is not simply a number a partner may type — see `allow_zero_deposit`.
     */
    case Agency = 'agency';

    /**
     * The guest pays a deposit to us and the balance at the property.
     *
     * The default, and the reason is arithmetic rather than preference: set
     * the deposit equal to the commission and nothing has to move between us
     * and the partner at all — no payout run, no statement, no reconciliation.
     */
    case Split = 'split';

    /**
     * We are the merchant. The guest pays us everything and we pay the partner
     * the folio less commission.
     *
     * Commission is certain; in exchange we hold other people's money, with
     * the payouts, refunds, chargebacks and regulatory posture that brings.
     * A company decision, not a code decision.
     */
    case Merchant = 'merchant';

    /**
     * Which model a deposit share means.
     *
     * The whole configuration surface, in one function. Compared to the cent
     * of a percent rather than with `==` on floats, for the same reason every
     * money comparison here is.
     */
    public static function forDepositRate(float $depositRate): self
    {
        $rate = (int) round($depositRate * 1000);

        return match (true) {
            $rate <= 0 => self::Agency,
            $rate >= 100_000 => self::Merchant,
            default => self::Split,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency — the property collects',
            self::Split => 'Split — deposit to us, balance at the property',
            self::Merchant => 'Merchant — we collect',
        };
    }

    /** What a partner needs to understand about the arrangement they are in. */
    public function description(): string
    {
        return match ($this) {
            self::Agency => 'Your guests pay you directly. We invoice you for commission afterwards, with payment terms.',
            self::Split => 'Your guests pay a deposit to us and the balance to you on arrival.',
            self::Merchant => 'Your guests pay us the whole amount. We pay you the balance less commission.',
        };
    }

    /** Whether our commission has to be asked for rather than kept. */
    public function commissionMustBeInvoiced(): bool
    {
        return $this === self::Agency;
    }
}

# Money: folio, payments, invoice, and who collects

Commissioned 2026-08-12. Nothing in this document is built yet.

This is the companion to `BOOKING_SYSTEM.md`, which deliberately excluded folio
and payments (§7) while the calendar was being built. That exclusion is now
lifted. A booking system that cannot say whether a stay has been paid is not a
booking system, and today's cannot: the reservation carries `total_amount`,
`charges_amount`, `discount_amount` and `currency` — the whole debit side — and
there is no credit side at all. No payment record, no invoice, no invoice
number.

Read `BOOKING_SYSTEM.md` first for the calendar and the reservation; this
document starts where a confirmed stay does.

---

## 1. The one distinction that keeps this simple

Two questions get confused constantly, and separating them is most of the design:

- **Recording** — what does this stay owe, what has been paid against it, when,
  by which method, and what document says so.
- **Collecting** — whose bank account the guest's money actually lands in.

**Recording is identical in every business model.** Cash handed over the desk at
a Namibian lodge is a payment; a Stripe capture is a payment; an EFT that arrives
three days later is a payment. Same record, same effect on the balance. Build
this once and it is finished.

**Collecting is where partners differ**, and — decided 2026-08-12 — we offer
more than one model, because partners genuinely want different things and
insisting on one would cost us the ones who want the other.

Everything below follows from taking that line seriously: the ledger is
universal, the settlement model is a per-partner strategy on top of it.

---

## 2. The three settlement models

These are the industry's three, not ours. Booking.com runs the first, Expedia
the second, most DMCs and specialist agents the third — which matters both
because a lodge owner has likely met them before and because it means we are not
inventing vocabulary. Per `CLAUDE.md` → "Standards", the industry names are the
names we use.

### A. Agency (partner collect)

The property is the merchant. The guest pays the property directly — the partner
issues the invoice and sends its own payment link, or takes an EFT, or takes a
card at the desk. We touch no guest money.

- **Our commission** is invoiced to the partner afterwards and has to be
  collected. This is the only model with **collection risk on our side**, and the
  only one that needs a partner-facing statement, a dunning process, and a
  decision about what happens to a partner who does not pay.
- Simplest to sell to a suspicious owner ("you keep control of the money"), most
  expensive for us to operate.

### B. Merchant (NamibWay collect)

We are the merchant. The guest pays us the whole amount; we pay the partner the
folio total less commission after an agreed trigger (typically after check-in, or
after the cancellation deadline passes).

- Commission is certain — we simply do not pay it out.
- In exchange we hold other people's money: payouts, refunds, chargebacks,
  currency (guest paying EUR, lodge paid NAD), and a genuinely different
  regulatory posture. **This is a company decision, not a code decision.**
- Some partners will actively want this. A small owner-run lodge with no card
  facility is exactly the case where "you do everything" is the selling point.

### C. Split (deposit to us, balance at the property) — the default

The guest pays a deposit online to us; the balance is paid at the property on
arrival or departure. Both amounts land on the same folio as two payments with
different recipients.

- **The commission collects itself.** Nothing is invoiced, nothing is chased.
- If the deposit is set exactly equal to the commission, **no money moves between
  us and the partner at all** — no payout run, no partner statement, no
  reconciliation. That is a real operational saving and the reason this is the
  default.
- But deposit and commission are two different numbers and must stay two fields.
  A 5 % deposit is a weak commitment signal, and part of the point of taking a
  deposit is that the partner can see the guest is serious. Where the deposit
  exceeds the commission, the difference is owed to the partner — model B's
  payout machinery, at a smaller amount.

### What actually varies

Stated as data, so it is obvious how little the models differ underneath:

| | Who is merchant | We collect | Owed afterwards |
|---|---|---|---|
| A Agency | Partner | nothing | partner → us (commission) |
| B Merchant | NamibWay | 100 % | us → partner (folio − commission) |
| C Split | both | deposit | us → partner (deposit − commission), often zero |

Three rows of one table. The folio, the payment records, the invoice, the
commission calculation and the moment commission is *earned* are the same in all
three; only the direction and timing of the net transfer change.

So: `Partner.settlement_model` as an enum with a strategy class per value, the
same shape as `PricingStrategy` and `ConnectorFactory` already use. The strategy
answers three questions and nothing else — what do we ask the guest for now, what
do we owe or claim afterwards, and who issues the guest's invoice.

---

## 3. Commission is earned once, however it is collected

Worth stating separately because it is the thing that must **not** live inside
the settlement strategies, or the three models will drift into three different
answers to "what do we earn":

- **Rate:** a per-partner percentage, defaulting to the platform rate. `CLAUDE.md`
  models ~5 %, deliberately below OTA rates; the actual number is commercial and
  belongs in config plus a partner override, never hardcoded at a call site.
- **Base:** the stay, before taxes and levies. Charging commission on the
  government's VAT and Tourism Levy is indefensible in front of an operator, and
  `ChargeKind` already distinguishes `Tax`/`Levy` from `Fee` — which is what makes
  this expressible rather than a guess.
- **Earned when:** the stay is confirmed and the cancellation window has closed;
  reversed if the stay is cancelled. A no-show is a policy question, not a
  technical one, and needs an answer before the first partner signs.
- **Stored as a result, not recomputed.** Same rule as the price: what we earned
  on a stay is a number written down at the time, with the rate that applied. A
  rate change next season must not silently rewrite last season's earnings.

---

## 4. The ledger — what gets built regardless of model

### Folio

What the stay owes, itemised: nights (from `ReservationNight`), extras and
taxes (from `ReservationCharge`), discount, total. Most of this exists as
totals on `Reservation`; what is missing is that it be readable as a statement
rather than three columns.

The folio belongs to the reservation and outlives its status. A cancelled stay
with a forfeited deposit still has a balance, and that is exactly the case a
system without a folio gets wrong.

### Payments

One row per money movement against a folio: amount, currency, date received,
method (cash, card, EFT, online), who it was received *by* (us or the partner —
this is what makes model C expressible), a reference, who recorded it, a note.

- **A refund is a negative payment on the same folio**, not a separate table and
  not a deletion. The audit trail is the product here: "we refunded N$ 2,400 on
  14 September" is a fact somebody will have to defend a year later.
- **Nothing is ever edited.** A payment entered wrongly is reversed and re-entered.
  This is the same discipline as `InventoryWriter` being the single write path,
  for the same reason.
- **Recorded ≠ cleared.** An EFT that a partner says has arrived and one Stripe
  has confirmed are not the same certainty; the record needs to say which.

### Balance and status

Open / part-paid / paid / overpaid, **stored on the reservation as a result**,
not summed on every read — same rule as the price, and for the same reason: the
arrivals board and the unpaid list must not each recompute it and disagree.

### Invoice

A legal document, not a PDF export. This is a "take the standard, invent
nothing" area:

- **Sequential numbering with no gaps**, per issuing entity and per year.
- **Immutable once issued.** A correction is a credit note that references it.
  Editing an issued invoice is the single thing this must make impossible.
- **VAT shown properly** — Namibia is 15 %, plus the Tourism Levy that
  `ChargeKind::Levy` already exists for.
- **Who issues it depends on the model:** in A and C's balance portion the
  partner invoices the guest, in B we do, and our commission is a separate
  document to the partner in A. So an invoice needs an issuer, not an assumption.

PDF rendering is the easy part — the Laravel PDF service already produces the
trip plan and the partner handbook.

---

## 5. Payment providers

The partner-configures-its-own-PSP idea is right, and the codebase already has
the shape for it: `Partner.connector_type` + `connector_config` + a factory. A
`PaymentProvider` interface with one class per provider is the same pattern, and
should be built the same way — including that **no provider has ever run against
a real account**, so all of it is unvalidated until one does.

Realities to design around rather than discover:

- **Stripe is for us, not necessarily for them.** Stripe does not support Namibia
  as a business location, and Stripe Connect cannot onboard a Namibian connected
  account — so "we collect and Stripe splits to the lodge automatically" is not
  available. Whether NamibWay itself can be the Stripe merchant depends on which
  entity holds the account, which is commercial and needs confirming before this
  is built on.
- **What Namibian and South African properties actually use:** DPO Pay, PayGate,
  Peach Payments, Ozow, Netcash, and — very commonly — plain bank transfer. A
  provider abstraction whose only implementation is Stripe will not survive
  contact with the market.
- **Bank transfer is a first-class method, not a fallback.** It has no callback:
  somebody marks it received. That is precisely why "recorded vs cleared" above
  is a field.
- **Currency.** Everything is stored in NAD (`config/currencies.php`, NAD pegged
  1:1 to ZAR); a guest may well pay in EUR or USD. The amount charged, the
  amount received, and the rate used are three separate facts and all three get
  stored — a payment converted at display time is a payment that will not
  reconcile.

---

## 6. Order of work

The order matters: each step is useful on its own, and none of them commits us to
a settlement model we have not chosen yet.

1. **Folio + payments + balance.** Record what is owed and what was paid, by any
   method, from the partner panel. Immediately useful with no PSP at all, because
   a desk takes cash today. This is also the smallest thing that answers the
   complaint that started this document.
2. **Invoice.** Numbered, immutable, VAT-correct, PDF, with a credit note for
   corrections.
3. **Settlement model per partner** — the enum, the three strategies, the
   commission calculation stored as a result.
4. **One payment provider, end to end**, for the deposit in model C. Which one
   depends on the answer to the Stripe-entity question above.
5. **Payouts and partner statements** — what models B and C owe the partner, and
   what model A claims from them. Last, because it is the only part that needs
   real money to have moved before it can be tested at all.

An honest note on sequencing: steps 1 and 2 are ordinary work. Step 4 onward is
where a real bank account, a real entity and a real partner are prerequisites, and
no amount of code substitutes for them.

---

## 7. Deliberately not here

Naming these so they are not assumed:

Multi-currency settlement (we pay partners in NAD), guest-facing saved cards,
instalment plans, dynamic cancellation policies with per-rate-plan penalty
schedules, revenue reporting beyond what an invoice list gives, and any
accounting-system export. Each is reasonable; none is needed to take money.

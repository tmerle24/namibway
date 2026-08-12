# Money: folio, payments, invoice, and who collects

Commissioned 2026-08-12. **Built 2026-08-12** — slices 1 to 6 of
`PAYMENTS_BUILD.md`; only step 6 of §6 below (payouts and partner statements)
is outstanding, and it is the one that needs real money to have moved before it
can be tested at all.

Read the rest of this document as the design it still is. Where the build
departed from it or answered something it left open, the section says so.

This is the companion to `BOOKING_SYSTEM.md`, which deliberately excluded folio
and payments (§7) while the calendar was being built. That exclusion is now
lifted. A booking system that cannot say whether a stay has been paid is not a
booking system, and the one described here could not: the reservation carried
`total_amount`, `charges_amount`, `discount_amount` and `currency` — the whole
debit side — and there was no credit side at all. No payment record, no
invoice, no invoice number. All three exist now.

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

### The model is not a fourth setting — the deposit share picks it

Decided 2026-08-12, and it is the thing that keeps this from becoming a
configuration screen nobody understands. Read the table's middle column: the
models differ in *what share of the folio we collect*, and nothing else.

- **0 % → agency.** We collect nothing and invoice the partner for commission.
- **between → split.** We collect the deposit, the property collects the balance.
- **100 % → merchant.** We collect everything and pay out net.

One number, three behaviours, no way to configure a combination that contradicts
itself. A partner cannot end up in "merchant model but we collect nothing".

**What happens at 0 % is a real consequence and must be shown at the moment it is
chosen, not discovered a month later.** It means we raise a commission invoice to
that partner, with payment terms, a dunning process when it goes unpaid, and the
question of what we do about a partner who simply does not pay — for which our
only real lever is `Partner.booking_enabled`. That is not a rounding difference
from the other two models; it is the one where our money depends on someone
else's willingness.

So two guards, both worth their small cost:

- **A platform-wide minimum deposit**, defaulting to the commission rate. At that
  floor model C nets to exactly zero between us and the partner, which is the
  cheapest arrangement that exists for both sides — it is a sensible thing to
  make the natural landing spot.
- **0 % is not simply a number a partner may type.** It has to be unlocked per
  partner by us (a flag on the partner), because choosing it moves cost and risk
  onto us and that is not the partner's decision alone. The partner still chooses
  their deposit; we choose whether zero is in their range.

---

## 2a. Who sets which number

Two rates, two very different owners — decided 2026-08-12:

- **Commission is ours.** Base 5 %, set in admin settings; adjustable per partner
  and per listing, but **only by us**. It never appears as an editable field in
  the partner panel. A partner may of course see what they pay.
- **The deposit is the partner's.** Base 15 %, set in admin settings as the
  default, and the partner adjusts it for their own property within the range we
  allow (see the floor and the 0 % unlock above).

Both resolve the same way, and it is a rule the codebase already uses elsewhere:
**most specific wins, null means inherit.**

    listing override → partner override → platform setting → config default

This is deliberately the same shape as the availability calendar, where a missing
row or a null override means "follow the unit's default" (`CalendarSnapshot`).
Same rule, same reason: sparse storage, one place to change the common case, and
no row that has to be written just to say "unchanged".

Both levels exist for both rates because both cases are real: a partner with
twenty camps wants one number; the one lodge in that group we negotiated
separately needs its own. Listing-level is the exception, not the workflow.

The platform defaults live in a single-row settings model with a Filament page,
the pattern `MessageSettings` + `MessagingSettings` already establishes — not in
`config/`, because the point is that the team changes them without a deploy. A
`config/` value stays as the fallback the settings row is seeded from.

**And, as always: stored as a result.** The commission rate and deposit rate that
applied are written onto the reservation when it is taken. Changing the platform
rate next season must not silently rewrite what we earned last season. Same rule
as the price, for the same reason.

---

## 2b. Booking system or PMS — the customer decides at setup

Decided 2026-08-12: NamibWay is both, and which one a given partner gets is a
choice made when their account is set up, not a fork in the product.

- **Booking-only** — sell the room, take the request, keep the calendar. What
  exists today.
- **Full (PMS)** — additionally the desk: room-level assignment, an in-house /
  checked-out status on a stay, extras posted to the folio, housekeeping, day-end
  reporting.

One rule makes this safe rather than a second product: **the mode decides what a
partner sees, never how anything is recorded.** A booking-only property still has
a folio, still has payment records, still has an invoice — it simply has no
screens for posting a bar bill to a room. The moment the mode changes what is
*stored*, upgrading a partner from booking-only to full becomes a migration, and
two partners on the same server stop being comparable. That is exactly the "two
systems" problem this whole workstream exists to avoid, reintroduced from the
inside.

`Partner.operating_mode` as an enum, read by the partner panel's navigation and
by nothing in `app/Services`.

---

## 3. Commission is earned once, however it is collected

Worth stating separately because it is the thing that must **not** live inside
the settlement strategies, or the three models will drift into three different
answers to "what do we earn":

- **Rate:** resolved by §2a — listing, then partner, then the platform setting,
  which starts at 5 % (`CLAUDE.md` models ~5 %, deliberately below OTA rates).
  Ours to set at every level; never hardcoded at a call site.
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

- **NamibWay will be a Namibian company** (decided 2026-08-12), which settles the
  Stripe question rather than leaving it open: Stripe does not support Namibia as
  a business location, so **Stripe is not the provider** — not for us, and Stripe
  Connect cannot onboard a Namibian connected account either, so an automatic
  split to the lodge is not on the table under any arrangement.
- **So the real provider is a decision that has not been made**, and it is
  commercial before it is technical: a merchant account with a Namibian bank
  (FNB, Bank Windhoek, Standard Bank Namibia all offer e-commerce acquiring),
  or a gateway operating in the region — DPO Pay, Peach, PayGate, Ozow, Netcash,
  PayToday. **Treat every name in that list as a candidate to verify, not a fact:
  availability, fees and settlement terms in Namibia specifically all need
  checking with the provider.** What matters for the code is that this list
  exists at all — a provider abstraction whose only implementation is one
  gateway will not survive contact with it.
- **Paystack, checked 2026-08-12 and implemented anyway.** It was raised as the
  likely choice, so it is worth writing down what it can and cannot do.
  Paystack's merchant countries are Nigeria, Ghana, Kenya, South Africa and
  Côte d'Ivoire, with Egypt and Rwanda more recent — **Namibia is not among
  them**, so a Namibian entity cannot open an account. This is the same wall
  Stripe presented, for the same reason, and it does not go away by asking
  differently. The route that *does* work is a **South African entity settling
  in ZAR**; NAD is pegged 1:1 to ZAR under the Common Monetary Area, so a
  Namibian folio charged in rand is an identity rather than a conversion
  anybody has to trust. Whether to have such an entity is a decision about the
  company. `PaystackProvider` exists so that decision is cheap either way —
  it is one class behind the interface, and the demo provider remains the
  default until a real merchant account exists.
- **Therefore build against a `DemoProvider` first, and mean it.** The
  requirement (2026-08-12) is that the whole flow works end to end in a demo
  before any real provider is chosen: authorise, capture, fail, refund, and the
  asynchronous callback, all simulated in-process with no network. Two things
  fall out of that, both good. A prospect sees their own lodge taking a deposit
  in the demo tenant that `booking:demo-tenant` already builds. And when the real
  provider is picked, it is one class and a configuration entry — nothing above
  it has to change, because nothing above it was ever written against a
  particular gateway.
- **Bank transfer is a first-class method, not a fallback.** It has no callback:
  somebody marks it received. That is precisely why "recorded vs cleared" above
  is a field. It is also, realistically, how a good share of Namibian lodge
  business is settled today.
- **Currency.** Everything is stored in NAD (`config/currencies.php`, NAD pegged
  1:1 to ZAR); a guest may well pay in EUR or USD. The amount charged, the
  amount received, and the rate used are three separate facts and all three get
  stored — a payment converted at display time is a payment that will not
  reconcile.

---

## 6. Order of work

The order matters: each step is useful on its own, and none of them commits us to
a settlement model we have not chosen yet. `PAYMENTS_BUILD.md` turns this into a
brief that can be worked through slice by slice.

1. ✅ **Folio + payments + balance.** Record what is owed and what was paid, by any
   method, from the partner panel. Immediately useful with no PSP at all, because
   a desk takes cash today. This is also the smallest thing that answers the
   complaint that started this document.
2. ✅ **Invoice.** Numbered, immutable, VAT-correct, PDF, with a credit note for
   corrections.
3. ✅ **The two rates and where they are set** — §2a's resolution chain, the
   platform settings page, the partner and listing overrides, and both rates
   frozen onto the reservation when it is taken.
4. ✅ **Settlement model per partner** — the deposit share picks it, the three
   strategies, the commission earned as a stored result, and the 0 % unlock with
   its consequence shown where it is chosen.
5. ✅ **The provider abstraction and a demo provider that fully works** — authorise,
   capture, fail, refund, asynchronous callback, all simulated, wired into the
   demo tenant so the flow can be shown before a real gateway exists. Paystack
   is implemented alongside it; the demo stays the default.
6. ⬜ **Payouts and partner statements** — what models B and C owe the partner, and
   what model A claims from them. Last, because it is the only part that needs
   real money to have moved before it can be tested at all. `SettlementBalance`
   already answers *what* is owed on one stay and in which direction; what is
   missing is the run that aggregates it, the statement a partner reads, and the
   record of a transfer having happened.

An honest note on sequencing: steps 1 through 5 are ordinary work and were done
in a day — the demo provider is precisely what makes step 5 possible without a
bank account. Step 6, and swapping the demo provider for a real one, need a real
entity, a real merchant account and a real partner, and no amount of code
substitutes for them.

## What the build decided that this document left open

Recorded here rather than only in commit messages, because these are the answers
somebody will look for next:

- **A declined payment produces no `payments` row.** `payment_intents` is money
  we asked for; `payments` is money that moved. `PaymentStatus::Failed` is for
  the other case — an EFT recorded as received that the bank did not honour.
- **A `recorded` payment counts towards the balance**; only a `failed` one stops
  counting. A desk handed cash is not waiting for a bank.
- **A stay nobody has priced yet has no balance**, not a zero one, and money
  against it reads as part-paid — which is what puts it on the unpaid list.
- **No stored invoice PDF.** The frozen line snapshot is the document; the PDF is
  a derivative rendered on demand through an authorised route. An invoice names a
  guest and says what they paid, and the media bucket is public.
- **Commission is earned at confirmation**, reversed by a plain cancellation and
  kept by a late one or a no-show. This is a *default*, not the answer to
  `PAYMENTS_BUILD.md` § D — see `App\Services\Payments\CommissionPolicy`, which
  exists so the answer changes one file.
- **A NAD folio is charged in ZAR** where the gateway cannot settle NAD, at the
  Common Monetary Area peg, with all three currency facts stored on the intent so
  a refund returns the money that was taken.

---

## 7. Deliberately not here

Naming these so they are not assumed:

Multi-currency settlement (we pay partners in NAD), guest-facing saved cards,
instalment plans, dynamic cancellation policies with per-rate-plan penalty
schedules, revenue reporting beyond what an invoice list gives, and any
accounting-system export. Each is reasonable; none is needed to take money.

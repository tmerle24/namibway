# Build brief: the money side

Written 2026-08-12, to be worked through on its own. The *what* and the *why*
live in `PAYMENTS.md`; this file is the *how* — the slices, what each one has to
prove before it counts as done, the decisions that are already made and must not
be re-opened, and the points where the work has to stop and ask.

**Worked through on 2026-08-12.** All six slices are built, plus a seventh thing
this brief did not ask for and the work turned up: `DpoProvider`, because DPO Pay
by Network is the only candidate in `PAYMENTS.md` § 5 that actually operates in
Namibia. The file is kept as written — a brief that quietly becomes a changelog
stops being usable as either — so read the slices below as *what each one had to
prove*, and `PROJECT_STATUS.md`'s dated entries for what came out of them.

Two parts of it are still live rather than historical: § B's guardrails, which
are the rules any later change to this code has to keep, and § D's "stop and
ask" list, which is the set of commercial decisions the build deliberately did
not make. Two of those are still unanswered and are now the oldest open items on
the money side — exactly when commission counts as earned, and payment terms
under the agency model.

Everything below this line is as commissioned.

---

## How to use this

Paste the prompt in § A into a fresh session. It is written to be worked slice by
slice, with a commit and a green `composer ci:check` at each boundary, so it can
be stopped after any slice and still leave the repository in a shippable state.

Slices 1 to 5 build on each other in order. **Slice 6 is independent** and can be
done first, last, or by someone else — it is the booking-system-or-PMS switch and
touches no money.

---

## A. The prompt

```
Read CLAUDE.md, then PAYMENTS.md, then PAYMENTS_BUILD.md, then the parts of
BOOKING_SYSTEM.md about the reservation and about prices being stored as results.
Then build the money side of the booking system, slice by slice, in the order
PAYMENTS_BUILD.md gives.

Work one slice at a time. For each slice: build it, write the tests the slice's
"proves" list names, run `composer ci:check` until it is green, commit with a
message that explains the reasoning and not just the change, and only then start
the next slice. Do not batch several slices into one commit.

The design decisions in PAYMENTS.md are settled — the three settlement models,
the deposit share selecting the model, who sets which rate, the resolution chain,
recording versus collecting. Do not re-open them. If building reveals that one of
them is actually wrong, stop and say so rather than quietly doing something else,
and add what you learned to PAYMENTS.md.

The guardrails in PAYMENTS_BUILD.md § B are not suggestions; a slice that
violates one is not done. Where PAYMENTS_BUILD.md names a table or a class, treat
it as the intended shape rather than a fixed spelling — a better name is fine, a
different structure needs a sentence in the commit message saying why.

Stop and ask about anything in PAYMENTS_BUILD.md § D. Everything else, decide
yourself and write down what you decided.

When you finish a slice, add a dated entry to PROJECT_STATUS.md the way the
existing entries are written. When you finish the last one you touch, update
PAYMENTS.md so it describes what exists rather than what was planned.

Push to a branch and open a draft pull request.
```

---

## B. Guardrails

These come from mistakes this codebase has already made or narrowly avoided. Each
one is cheap to honour now and expensive to retrofit.

1. **One write path for money.** Every insert into `payments` goes through a
   single service — `PaymentRecorder` or whatever it ends up called — exactly as
   every inventory mutation goes through `InventoryWriter`. Enforce it the same
   way it is already enforced there: a runtime model guard *and* an architecture
   test that fails on a query-builder write from anywhere else. Copy the existing
   pattern rather than inventing a second one.

2. **Nothing is ever edited or deleted.** A wrong payment is reversed by a
   negative payment that references it, and an issued invoice is corrected by a
   credit note that references it. The only field that may change after creation
   is a payment's status (`recorded` → `cleared` / `failed`), because that is a
   fact arriving late rather than a fact being rewritten.

3. **Amounts are `decimal(12,2)` columns, matching `reservations.total_amount`.**
   The house convention casts them to `float` in PHP, so follow it — but never
   decide "is this fully paid?" with `==` on floats. Compare in whole cents
   (`(int) round($amount * 100)`). A stay that shows as unpaid because of a
   0.00000001 difference is the kind of bug that gets found by a guest at
   check-out.

4. **A payment records three separate facts about currency**, not one: what was
   owed (NAD, `config/currencies.php`), what the guest actually handed over and in
   which currency, and the rate used. Converting at display time produces a ledger
   that cannot be reconciled.

5. **Rates are frozen onto the reservation** when it is taken — commission rate,
   deposit rate, settlement model, and the amounts they produced. Changing a
   platform setting must never alter what a past booking earned. This is the same
   rule as the price and for the same reason.

6. **Invoice numbering is gapless per series and year**, and that means a locked
   counter row (`SELECT … FOR UPDATE` on a sequence row inside the issuing
   transaction), never `max(number) + 1` and never a global auto-increment. Two
   check-outs at the same desk in the same second must not produce the same
   number or skip one.

7. **The mode never changes what is stored** (see slice 6). `Partner.operating_mode`
   may be read by the panel's navigation and by nothing in `app/Services`. There
   should be a test that says so.

8. **No real payment provider, and no network calls to one.** Slice 5 builds the
   abstraction and a demo implementation only. If a slice seems to need a real
   gateway, it is out of scope — see § D.

9. **English everywhere**, including the Filament partner and admin panels — see
   `CLAUDE.md`. And money is traveller-facing in places, so the traveller-facing
   strings go through `resources/js/lang/*.json` like the rest.

---

## C. The slices

Each slice lists what it is for, roughly what it consists of, and — the part that
matters — what it has to **prove**. The "proves" list is the acceptance criteria;
if a line there has no test, the slice is not done.

### Slice 1 — the ledger

**For:** so the system can answer "has this stay been paid, when, how much, how".
Useful immediately with no payment provider at all, because a lodge desk takes
cash today.

**Roughly:**

- `payments`: reservation, amount, currency, received amount + currency + rate
  where they differ, `received_at`, method (`cash`, `card`, `eft`, `online`,
  `other`), `collected_by` (`namibway` / `partner` — this is what makes the split
  model expressible), status (`recorded` / `cleared` / `failed`), reference,
  `reverses_payment_id`, `recorded_by`, note.
- `PaymentRecorder` + the guard + the architecture test (guardrail 1).
- `paid_amount` and `payment_status` (`unpaid` / `part_paid` / `paid` /
  `overpaid`) stored on the reservation, rewritten by the recorder on every write.
- A folio view of the stay: nights, charges, discount, total, payments, balance.
  **The reservation is the folio** — do not add a `folios` table. Split folios
  (room account vs extras vs company account) are a real PMS feature and a known
  future need; note it, do not build it.
- Partner panel: record a payment and record a refund on a reservation; an
  outstanding-balance column on the arrivals board; an unpaid list.

**Proves:**

- A payment moves the balance and the status, and a refund moves them back.
- Two payments that together equal the total leave the stay `paid`; one cent short
  leaves it `part_paid` (this is guardrail 3's test).
- A payment cannot be written outside `PaymentRecorder` — both the runtime guard
  and the architecture test.
- A reversal references the payment it reverses and neither row is mutated.
- A guest paying EUR against a NAD folio stores all three facts and still
  balances.
- A cancelled stay keeps its folio and its balance.

### Slice 2 — the invoice

**For:** because there is no invoice at all today, and an invoice is a legal
document rather than a PDF export.

**Roughly:**

- `invoices`: number, series, `issued_at`, issuer (`namibway` / `partner`), kind
  (`stay` / `commission` / `credit_note`), reservation or partner, currency,
  subtotal, tax, total, a frozen JSON snapshot of the lines as issued, PDF path.
- A sequence table with the locked counter (guardrail 6).
- Issue from a reservation; render the PDF with the existing Laravel PDF service.
- Credit note referencing the invoice it corrects.
- VAT and Tourism Levy shown as their own lines — `ChargeKind` already separates
  `Tax` and `Levy` from `Fee`, so this is a mapping, not a new concept.

**Proves:**

- Numbers are gapless and unique per series and year, including under two
  concurrent issues (write the test that actually runs them concurrently).
- A rolled-back issue leaves no gap.
- An issued invoice cannot be modified — attempting it fails loudly.
- A credit note references its invoice and the pair nets to the corrected amount.
- The snapshot survives the underlying rate plan or charge being changed
  afterwards.

### Slice 3 — the two rates and where they are set

**For:** `PAYMENTS.md` § 2a. Commission is ours, the deposit is the partner's, and
both resolve most-specific-first.

**Roughly:**

- A single-row platform settings model with a Filament admin page, following
  `MessageSettings` + `MessagingSettings`: `commission_rate` (5 %),
  `deposit_rate` (15 %), `minimum_deposit_rate`. Seeded from `config/`, which
  stays as the fallback — the point of the settings row is that the team changes
  these without a deploy.
- Nullable `commission_rate` and `deposit_rate` on `partners` and on `listings`.
- A resolver: listing → partner → platform setting → config default, null meaning
  inherit — deliberately the same rule as the availability calendar's sparse
  overrides.
- Admin edits both rates at every level. The partner panel edits **only** the
  deposit, and only within the allowed range.
- Both rates and their amounts frozen onto the reservation at booking time.

**Proves:**

- Each level of the chain overrides the one above it, and a null falls through.
- The partner panel offers no way to change commission — including by posting the
  field directly.
- A platform rate change does not alter an existing reservation's frozen rate.
- A deposit below the floor is refused with a message that says what the floor is.

### Slice 4 — the settlement models

**For:** `PAYMENTS.md` § 2. Three models, chosen by one number.

**Roughly:**

- `SettlementModel` enum (`agency` / `split` / `merchant`), **derived** from the
  effective deposit share: 0 % → agency, 100 % → merchant, between → split. It is
  not a fourth independent setting and must not be storable as one that
  contradicts the deposit.
- A strategy class per model answering exactly three questions: what do we ask the
  guest for now, what is owed between us and the partner afterwards, and who
  issues the guest's invoice.
- Commission earned at a named moment, stored as a result on the reservation, and
  reversed on cancellation.
- Commission base is the stay **before** tax and levy.
- `allow_zero_deposit` on the partner, admin-only. Where it is off, 0 % is not
  selectable. Where it is on, the partner panel states the consequence next to the
  field — we will invoice you for commission — rather than letting it be
  discovered later.

**Proves:**

- Each of the three deposit shares produces the expected model, and the model
  cannot be set to something the deposit contradicts.
- Commission excludes tax and levy — build a stay with both and assert the base.
- A deposit equal to the commission leaves nothing owed in either direction. This
  is the case the default exists for, so it deserves an explicit test.
- Cancelling a stay reverses the earned commission.
- A partner without the unlock cannot reach 0 %, by UI and by direct request.

### Slice 5 — the provider abstraction and a demo that fully works

**For:** so the whole flow can be demonstrated, and sold, before NamibWay has a
merchant account — and so that adopting the real gateway later is one class.

**Roughly:**

- A `PaymentProvider` interface: create an intent, capture, refund, handle an
  asynchronous callback. A factory resolving it, the same shape as
  `ConnectorFactory`.
- `DemoProvider`, implementing all of it in-process with no network, and a fake
  hosted-checkout page inside the app with buttons for *pay*, *decline* and
  *abandon*, plus a callback that arrives after the redirect rather than during
  it — because that ordering is where real integrations break, and a demo that
  hides it teaches the wrong shape.
- A deposit payment link on a booking, and a callback that lands as a `payments`
  row through `PaymentRecorder` like everything else.
- Wired into the demo tenant that `booking:demo-tenant` already builds, so a
  prospect sees their own lodge taking a deposit.

**Proves:**

- The happy path produces exactly one payment row; a declined payment produces
  none, or one marked `failed` — decide which and write down why.
- A callback delivered twice produces one payment, not two. (Idempotency here is
  the same discipline as `reservations.inquiry_id` being unique.)
- A callback arriving before the guest returns from the redirect still works.
- A refund through the provider lands as a negative payment.
- Nothing above the interface names a provider.

### Slice 6 — booking system or PMS, chosen at setup

**Independent of the money slices.** `PAYMENTS.md` § 2b.

**Roughly:** `Partner.operating_mode` (`booking_only` / `full`), set when the
account is created and changeable by us. The partner panel's navigation and the
reservation screen show or hide the desk features — room-level assignment,
in-house / checked-out status, posting extras to the folio, housekeeping, day-end
reporting — as those exist. Today most of them do not, so this slice is mostly the
switch, the gating, and the guard.

**Proves:**

- A `booking_only` partner sees no desk navigation; a `full` partner does.
- **A `booking_only` partner still has a folio, payment records and an invoice.**
  This is the load-bearing test of the whole slice: the mode hides screens, it
  never changes what is stored, or upgrading a partner becomes a migration and two
  partners on one server stop being comparable.
- Nothing under `app/Services` reads `operating_mode` — assert it the way the
  inventory architecture test asserts its rule.

---

## D. Stop and ask

Everything else is a decision to make and write down. These are not:

- **Which real payment provider.** Commercial, and blocked on NamibWay's Namibian
  entity and merchant account. Slice 5 builds the demo; picking the gateway is not
  a code decision. (`PAYMENTS.md` § 5 lists candidates to verify — none of them is
  confirmed.)
- **The actual commission and deposit percentages** beyond the 5 % / 15 % starting
  values, and the minimum-deposit floor.
- **When commission is earned**, precisely — at confirmation, at the cancellation
  deadline, or after check-in — and what a no-show earns. There is a sensible
  default; the answer is commercial and affects what partners are told.
- **Payment terms and what happens to a partner who does not pay** a commission
  invoice under the agency model. The only technical lever is
  `Partner.booking_enabled`, and using it is a business decision.
- **Anything requiring a real bank account, entity, or partner.** Payouts and
  partner statements (§ 6 of `PAYMENTS.md`'s order of work) are deliberately not
  in the slices above for this reason.

---

## E. Definition of done, per slice

- `composer ci:check` green — that is eslint, prettier, `vue-tsc`, pint, phpstan
  and `artisan test`, exactly what CI runs.
- Every line in the slice's "proves" list has a test.
- The commit message explains the reasoning, in the style of the existing history.
- A dated entry in `PROJECT_STATUS.md`.
- The panel work has actually been looked at in a browser at a mobile width, not
  only asserted in a test. The last calendar slice produced nine bugs that only a
  browser showed.

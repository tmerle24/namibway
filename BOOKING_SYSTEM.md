# The lodge booking system — what it is and where it is going

**Written 2026-08-11.** The design a lodge-facing booking system is being built
towards, decided in review with the author. `PROJECT_STATUS.md` says where things
stand; this file says what the finished shape is and why it is that shape.

Read `CLAUDE.md` → "Standards" first. This document is an application of the rule
written there: take the established standard, and write down every deliberate
departure from it.

---

## 1. The goal, stated as a constraint

> We must be able to model whatever a partner asks for, so that we never have to
> say "that is not possible" — and the product must stay simple enough that a
> lodge manager can operate it and a small team can maintain it.

Those two pull against each other, and the usual failure is to resolve them by
making the data model general: configurable everything, a rules engine, prices as
formulas. That reliably produces a system nobody can operate and nobody can test.

The resolution is an **hourglass**: a very small core that never changes, all the
variety pushed to the edges, and the computed result frozen so that nothing
downstream has to understand any of it.

---

## 2. The core — deliberately tiny

Three things no accommodation system anywhere disagrees about. All three already
exist and are live.

| | What it is | Where it lives today |
|---|---|---|
| **Inventory** | How many units of a room type are free on a night | `room_type_calendar_days` counters, moved by a conditional `UPDATE` |
| **Restrictions** | Minimum stay, closed to arrival, closed to departure | same table |
| **The price of a stay, as a result** | An amount per night, recorded | `reservation_nights` |

The third is the load-bearing one. **What goes in the core is not *how* a price is
calculated, but *what came out*.** Every invoice, report, statement and connector
reads the recorded per-night amounts and never needs to know which pricing scheme
produced them. That single decision is what lets the calculation side stay varied
without the rest of the system paying for it.

Concurrency stays exactly where it is: availability is a counter moved by a
conditional `UPDATE`, so two people racing for the last room are resolved by the
database. Nothing in this plan touches that mechanism.

---

## 3. The edges — where variety is allowed

### Rate plans

A rate plan is a product the lodge sells, and it is the industry's own vocabulary —
ARI, the OTA schema and every channel manager have it. It carries:

- **Board basis** — B&B, DBB, full board
- **Cancellation terms** — refundable or not
- **Eligibility** — public, Namibian resident, SADC, agent, corporate

**Board is a rate plan, not a separate price dimension.** A lodge does not sell "a
room, and then dinner"; it sells a DBB rate and a B&B rate. Modelling it as two
rate plans matches how it is quoted, and removes a whole layer.

**Residency is a rate plan too.** NWR publishes Namibian-resident, SADC and
international prices side by side. Those are three rate plans, not a discount on a
guest.

### Pricing strategies

The price is a **function**, and which function applies is a property of the rate
plan. The set is closed, small, and each member is a class with a handful of
parameters:

- **per unit** — one amount for the room, however many people are in it
- **per occupancy** — an amount per number of guests (1, 2, 3, 4 …)
- **per person sharing** — an amount per person, plus a single supplement
- **age bands** — children priced as a share of the adult amount, boundaries set
  per property

A partner with a scheme we have not seen means **one new class**. No migration, no
change to the calendar, no change to availability. That is the technical meaning of
"we can do that".

Each strategy is a pure function, so it is tested with a table of examples. Without
that, "we support everything" becomes "we can verify nothing".

Age bands turned out not to be a strategy but a property of the guest category —
they apply the same way under every strategy, so a fourth class would have had to
be combined with the other three rather than chosen instead of them. The nightly
rate is what each strategy reads differently: per-unit reads it as the price of the
room, per-person-sharing as the price of one person sharing, and per-occupancy as
the fallback for a night whose guest counts nobody has entered.

What refuses rather than guesses, and why it matters more than it sounds: a plan
that prices by guests will not price a booking that names none; a night priced for
one and two guests refuses four rather than selling it at the two-guest price; and
a room priced for more people than it sleeps is refused before any of that. Each of
these is a sentence a receptionist can act on, not a number nobody checks until
check-out.

### The OTA shape is already the general shape

This is worth stating plainly, because it removes the temptation to invent:
`BaseByGuestAmt` keyed by guest count covers per-unit pricing (a single amount),
occupancy pricing and per-person pricing all at once. `AdditionalGuestAmount` with
`AgeQualifyingCode` and min/max age covers children and extra adults. Rate plans
cover board, cancellation and eligibility.

We do not need a more general model than the standard. We need the standard.

---

## 4. Data model

### What changes in the calendar, and why

Today one table carries both inventory and rate. With rate plans those separate,
because **a room is sold once no matter how many rate plans it is offered under**:

- `room_type_calendar_days` **keeps the counters** — `units_total`, `units_sold`,
  `units_blocked` — per room type per night. Inventory is shared across rate plans.
  The concurrency mechanism and its process-forking test stay untouched.
- `rate_plan_days` is new — `rate_plan_id`, `room_type_id`, `date`, `rate`,
  `min_stay`, `closed_to_arrival`, `closed_to_departure`. Rates and restrictions
  differ per rate plan; availability does not.

Getting this split wrong in either direction is the expensive mistake. Counters per
rate plan would oversell the property; a shared rate would make rate plans
pointless.

### New tables

| Table | Holds |
|---|---|
| `rate_plans` | Per listing: name, code, board basis, cancellation terms, eligibility, pricing strategy, default flag |
| `rate_plan_days` | Rate and restrictions per rate plan × room type × night |
| `rate_plan_guest_amounts` | Base amount by guest count, per rate plan × room type × **night** |
| `guest_categories` | Per property: adult, child 3–11, infant 0–2 … name, age range, share of the adult price, whether it counts as an occupant |
| `promotions` | Discount, optional code, stay window, booking window, which rate plans and room types, minimum nights, usage cap |
| `amenities` + `room_type_amenity` | Amenity catalogue with categories, and what each room type has |

Two departures from the first draft of this table, both made while building step 2
and both worth stating rather than leaving to be discovered:

- **The guest amounts carry a date.** The draft hung them off the rate plan and the
  room type alone. That cannot express a season — 1000 / 1300 / 1500 in low season
  is 1400 / 1800 / 2100 in high — and the alternative was a second season mechanism
  beside the one the calendar already has. In OTA the amounts hang off the rate for
  a date, which is what this now does. It is more rows; it is also the standard.
- **`rate_plan_extra_guest_amounts` was not built.** Its job — what a child pays —
  is done by `guest_categories.charge_percent`, a share of the adult amount, which
  is how every rate sheet in the market states it and which keeps a child's price
  following the adult price through every season with no second set of numbers to
  maintain. A per-category *amount* can be added later if a real partner quotes
  that way; nothing here has to move for it.

### Changes to existing tables

- `reservation_units` gains `rate_plan_id`
- `reservation_guests` is new — guest category and count per unit line
- `rate_plans` gains `single_supplement_amount` and `single_supplement_percent`,
  because the market states the supplement both ways and converting at entry time
  would need the season to be known
- `reservations` gains the promotion applied and the discount recorded

### What does not change

`reservation_nights` keeps doing exactly what it does: one row per night with the
amount. Every strategy, every rate plan, every promotion ends there. That is the
frozen result.

---

## 5. The screens

**The calendar does not change.** Room types down, nights across, units free and a
rate in every cell. When a property has more than one rate plan a switcher appears
above it; a property with one never sees it. Whatever the calculation behind the
number, the picture stays the same.

**Booking form.** The `Units` field goes. In its place, per room line: guest
category and a count, with a plus button to add another category. A consequence
worth knowing: three rooms become three lines, each with its own occupants. That is
correct — each room has its own guests, and it is what makes per-occupancy pricing
possible at all.

**Rates page** gains a rate plan selector and the occupancy amounts.

**New pages**, each small: rate plans, guest categories, promotions. Amenities and
photos go on the existing room type editor.

### How it stays operable

- **Profiles, not configuration.** Setting a property up offers "Lodge, per person
  sharing, DBB" or "Guesthouse, per unit" and creates working rate plans. The
  operator chooses; they do not configure.
- **Unused things stay invisible.** One rate plan means no switcher. No child
  pricing means no age bands on screen. Complexity appears when it is asked for.
- **The set of strategies grows on demand.** Two are built now — per unit, and per
  person with occupancy and age bands. That covers Namibia. The rest arrive when a
  real partner needs them, in days rather than weeks. Shipping every conceivable
  option first is how the cathedral gets built before anyone prays in it.

---

## 6. Order of work

Each step leaves the system working and shippable.

**0. Done and live.** ARI substrate, occupancy calendar, arrivals board, manual
booking entry, stay lifecycle, blocks, bulk rate editing, dashboard widgets, the
panel on its own host.

**1. Rate plans and the calendar split. — Done, 2026-08-11.** Every listing got one
default rate plan carrying its current rates, so nothing changed on screen. This is
the migration that had to happen once and early — retrofitting a dimension into the
table the whole availability logic hangs on is the excavation the single write path
exists to prevent.

**2. Occupancy and guest categories. — Done, 2026-08-11.** All three strategies are
built (`App\Services\Pricing`, one class each, pure, checked against a table of
examples in `NightPricerTest`), along with `guest_categories`,
`rate_plan_guest_amounts`, `reservation_guests`, the rate plan and guest type
screens, and the booking form's occupancy rows. A room line with occupancy holds
one room, so two families on one booking are two lines with their own prices.

What a lodge does now: create a rate plan, choose how it is priced, press "add the
usual set" on Guest types if it prices by people, then enter the numbers on the
Rates screen. A property that prices per room sees none of it — no rate switcher,
no guest rows, no guest types — which is the rule this whole design is judged by.

**3. Board basis.** Mostly configuration and wording, because board is a rate plan.

**4. Promotions and codes.** They compute on a finished price, so they come after
step 2.

**5. Amenities, categories and photos.** Independent of everything above; can slot
in anywhere.

**6. `partners.booking_enabled` and per-partner demo mode.** A switch deciding
whether a lodge is live, and a demo address of the partner's own so an operator can
test bookings against their real inventory before being switched on. Until then,
booking mail goes to `team+<lodge>@namibway.com`. Last, because it sits on top of
everything else — and it replaces `booking:demo-tenant`, which exists only because
there was no other way to show the system working.

---

## 7. Deliberately not in this plan

Naming these matters as much as the plan, because a disabled button is a claim:

Channel synchronisation, iCal, room-level assignment (a reservation holds room
types and quantities, never a named room), folio and payments, housekeeping, tax
reporting, and the `Inquiry` → `Reservation` bridge, which is designed in
`CLAUDE.md` and still unbuilt.

Also worth repeating: **no connector has ever run against a real partner account.**
The market conventions this design follows are well established, but the data
models of ResRequest, NightsBridge and hopeCloud have not been seen from the
inside. The first real integration will produce surprises, and the pluggable
strategy is what makes absorbing them cheap.

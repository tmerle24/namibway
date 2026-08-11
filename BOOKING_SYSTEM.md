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

```
        rate plans · pricing strategies · promotions
        board basis · residency · taxes · channels          variety in
                            │
                            ▼
                ┌───────────────────────┐
                │  inventory            │
                │  dates and rules      │                   the core:
                │  rate                 │                   no business models,
                │  price, as a result   │                   only their results
                └───────────────────────┘
                            │
                            ▼
        booking · invoice · reporting · accounting          variety out
        connectors · channel managers
```

The sentence that makes it work: **the core knows no business models, only their
results.** Everything above the waist decides what a night costs; everything below
reads a number that was already decided and frozen.

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

### The five rules

Everything below is an application of these. They were written down before step 1
and hold whatever is built next; a change that breaks one of them is a change to
this document first.

1. **Inventory is physical.** It is counted per room type per night, never per rate
   plan. A room is sold once however many products it is offered under.
2. **A rate plan is a product, not the pricing engine.** It carries what is being
   sold — name, board, cancellation, eligibility — plus which calculation applies
   and that calculation's parameters. It does not contain the calculation.
3. **A pricing strategy computes; it never touches inventory.** Every pricer is a
   pure function of a context handed to it: no queries, no clock, no writes. It can
   therefore be checked against a table of examples, which is what makes "we
   support everything" a claim rather than a hope.
4. **The price is frozen at booking.** `reservation_nights` holds the amount per
   night as it was computed at the moment the stay was written. Nothing recomputes
   it, ever. A lodge raising a rate tomorrow does not make yesterday's booking more
   expensive, and no report has to reconstruct a price from rules that have since
   changed.
5. **A new pricing strategy is an extension, not a schema change.** One class and
   one enum case. Its parameters go in the rate plan's `pricing_config`, so the
   products table does not grow a column every time a calculation needs a number.

Rule 4 is worth being explicit about, because it is invisible when it works. The
path is one-way and each arrow is a step that cannot be run backwards:

```
rate plan + calendar  →  price calculation  →  reservation  →  reservation_nights
                                                                (frozen)
```

Availability is read before the calculation and the counters move inside the same
transaction that writes the stay, so a stay is never priced against inventory it
did not get.

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

### Taxes and levies — a layer, reserved, not built

Namibia charges VAT and a tourism levy, and a lodge may add a conservancy fee or a
park permit. None of it is built, and none of it belongs in a rate plan: a product
must never end up knowing "15% VAT plus 2% levy". The shape it will take when a
real partner needs it:

```
rate  →  price  →  charges (levy, VAT, fees)  →  total
```

Charges apply *to* a computed price, exactly as promotions do, which is why the two
sit at the same point in the chain and why neither is allowed to reach back into
the calculation. Two decisions have to be made when it is built, and are recorded
here so they are not made by accident in the meantime: whether the rate a lodge
enters is tax-inclusive or net (Southern African lodges quote both ways, so it is
per property, not per system), and whether a charge is frozen onto the reservation
like the price is. It is — rule 4 covers charges as much as rates; a VAT change
must not alter last month's invoices.

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

A guest category answers *who somebody is* — an age band, whether they are an adult
for the purpose of a single supplement, whether they count as an occupant. It
deliberately does **not** become `adult_price` / `child_price` / `infant_price`
anywhere: the counts go into a strategy, and the strategy produces a price. That
distinction is what lets an unusual scheme be one new class rather than three new
columns.

The one number a category does carry is `charge_percent`, and it is worth naming
the trade-off: it makes a child's share a **property-wide policy** rather than a
per-rate-plan one. That matches how lodges publish child policies (one line, all
rates), and it means a resident rate and an international rate cannot yet discount
children differently. When a partner needs that, it is an override table keyed by
rate plan and category — additive, no change to anything above.

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

**3. Board basis. — Done, 2026-08-11.** Mostly configuration and wording, because
board is a rate plan — so what this step actually built is everything *around* it:
the board basis and the plan's name frozen onto `reservation_units` at booking (a
plan renamed in March must not change what a stay sold in February says it
included, and `rate_plan_id` is `nullOnDelete`, so the link alone is not a record);
"sold as" on the stay detail and the arrivals board; rooms-by-board for tonight,
which is the kitchen's question; the rate plan switcher on the calendar the design
promised; and three setup profiles — guesthouse per room, lodge per person sharing
with DBB and B&B, and resident / SADC / international side by side.

The booking form was tightened at the same time. It stays **one page, not a
wizard**: a desk takes the same booking thirty times a day, every field depends on
the others, and steps would hide the total somebody is watching. What it gained is
what a long form always needs — a heading and a save button that stay put while the
middle scrolls, the total set in large type where it is read out to a guest, and
the price-override fields folded away, since a field used once a month costs a
screenful every other time.

**4. Promotions and codes. — Done, 2026-08-11.** Three kinds — a percentage, an
amount, and free nights, because "stay 4, pay 3" is what lodges here advertise and a
percentage would give a different number on every stay length. Two windows, and
they are not the same window: when the guest is *there* and when they *booked*, so
an early bird and a last-minute deal are both expressible.

Three decisions worth stating:

- **They never stack.** At most one offer per booking. Stacking is where discount
  systems stop being auditable and where two reasonable offers accidentally give
  sixty percent off a peak-season chalet. Where several public offers fit, the guest
  gets the best one, which is the only tie-break nobody has to defend.
- **A typed code beats a larger public offer.** Somebody handed that code to that
  guest for a reason, and substituting a different discount would make the agent's
  own paperwork wrong.
- **A code that does not work refuses the booking.** Quietly charging full price is
  how a guest finds out at check-out, and the refusal says which part failed — the
  dates, the rooms, or the cap — because "invalid code" is not something a desk can
  act on.

The cap is claimed by a conditional `UPDATE` inside the booking transaction, exactly
like an inventory counter: two people typing the last available code at once is the
same race as two people booking the last room. The discount is frozen onto the
reservation beside the price it came off — deleting a finished offer must not make
last month's bookings look mispriced.

**5. `partners.booking_enabled` and per-partner demo mode. — Done, 2026-08-11.**
Three states, and the only difference between them is who receives the mail: not
live (everything to `team+<lodge>@namibway.com`), test mode (everything to one
address the operator chose), live (guests and the lodge). The calendar, the prices
and the booking form are identical in all three, because a system a partner is
evaluating has to be the system they will get.

This is also where booking mail started existing at all: a stay now sends the guest
a confirmation and the property its copy, and a cancellation says so. `BookingMailbox`
is the single place that decides where any of it goes, and the partner panel carries
a notice saying so until a property is live — an operator who believes a guest was
written to has been misled by silence.

`booking:demo-tenant` still works and is now the lesser tool: a partner testing
against their own real inventory needs no invented sandbox. It stays until somebody
misses it.

**6. Amenities, categories and photos. — Done, 2026-08-11.** Room photos turned out
to exist already, editable in both panels; what was missing was amenities. There is
now **one catalogue everybody shares** — about forty entries grouped for reading
(beds, bathroom, comfort, outdoors, food, power, services, accessibility) — and a
room picks from it. Not one catalogue per property: a shared vocabulary is the only
thing that makes two rooms comparable, a filter possible, or a channel mapping cheap
later, and a list anybody can extend is one where "WiFi", "Wi-Fi" and "wifi" are
three amenities within a month. The team curates it under Content → Amenities;
retiring an entry hides it from the picker without rewriting what the rooms that
have it claim.

The catalogue is written from Namibian rate sheets rather than a generic hotel list:
mosquito nets, outdoor shower, braai facilities, star bed, and — because a guest who
is not told arrives with a dead camera battery — solar power, generator hours only,
and no power after dark.

The same catalogue covers the **property** as well as the room, with a scope on each
entry (`room`, `property`, `both`) rather than a second list — a pool belongs to the
lodge and a mosquito net to the chalet, but Wi-Fi is asked about at both levels and
as two entries the two would drift apart.

`listings.facilities` — the free-text array the scrapers and the AI extractor fill —
stays where it is, under one rule, which is CLAUDE.md's content-source ladder
applied to amenities: **once a property has chosen amenities, the free text is
ignored.** Not merged with it, which would put "pool" beside "Swimming pool" and
make an owner's own list look careless; and not deleted, because it remains the
record of what a source claimed and a listing nobody has claimed still needs
something to show. `amenities:backfill-listings` maps the free text across where it
can — the namibweb scraper emits exactly eleven keys, so that half is an exact
mapping — and reports what it could not place instead of guessing.

(An earlier draft of this section claimed migrating `facilities` risked losing
months of scraper work. That was wrong, and worth recording as wrong: the scrape
takes hours, nothing traveller-facing reads the column, and the vocabulary is
eleven keys.)

**7. Guests as a thing, not a name on a booking. — Not built.** Raised in review,
and it is the largest remaining gap in the lodge-facing product: there is no guest
menu, no guest search, no view of somebody's previous stays, and nowhere to write
down what a lodge knows about them.

The requirement behind it is worth stating in its own words, because it is not only
about one screen: **somebody at a reception desk has to be able to reach the same
data by whatever route they happen to be on.** By date on the calendar, by arrival
on the board, by reference, by name, by phone number, by the room somebody is in.
A system that has one path to each fact is a system that gets abandoned for a
notebook.

The data-model question this asks first: today `reservations` carries
`guest_name`, `guest_email` and `guest_phone` as free text on each booking, so
"this guest's last three stays" cannot be asked. A guest may also be a `User` with
an account who booked through the site, or the contact on an `Inquiry`, or a
walk-in somebody typed in at the desk. Whether those become one `Guest` record with
the others pointing at it — and how a desk merges two rows that turn out to be the
same person, which is the part every hotel system gets wrong — is the decision to
make before any screen is drawn.

Comments: a booking already takes a note, but only one, written when it is created.
What a desk actually wants is an append-only thread with who wrote what and when,
on the guest as well as on the stay — "always asks for the far chalet", "the
transfer driver has her number". That is a small table and a large difference at a
front desk.

Steps 5 and 6 were the other way round in the first draft. Switching a lodge on is
a rollout feature rather than a booking-core one, but it is what turns all of this
into something a real partner uses, and amenities have no dependency on anything
here at all.

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

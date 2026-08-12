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
| **Inventory** | How many units of a bookable unit are free on a night | `bookable_unit_calendar_days` counters, moved by a conditional `UPDATE` |
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

### Taxes and levies — built 2026-08-12

Namibia charges VAT and a tourism levy, and a lodge may add a conservancy fee or a
park permit. None of it belongs in a rate plan: a product must never end up knowing
"15% VAT plus 2% levy". So charges sit exactly where promotions sit —

```
rate  →  price  →  discount  →  charges  →  total
```

— applying *to* a computed price and never reaching back into it.
`App\Services\Pricing\ChargeCalculator` is the only thing that computes one, and
everything it may see arrives in a `ChargeableStay`: the finished amount, the
nights, the guests, the room-nights. It cannot reach the rate plan or the occupancy
machinery, so no charge can quietly become part of how a night is priced.

**Four bases**, which is what this market actually charges: a percentage (VAT, the
tourism levy), per person per night (a park permit, a conservancy fee), per room per
night (a bed levy), and once per booking.

The two decisions the earlier draft reserved, now made:

- **Included or added is a question about the charge, not about the property.** The
  draft said it would be per property, "because Southern African lodges quote both
  ways". Building it showed that is not expressive enough: the same lodge normally
  quotes a rate with VAT already inside it *and* adds a park permit on top. A
  property whose rates include everything simply marks everything included, so the
  per-charge answer subsumes the per-property one. An included charge is
  **extracted**, not added — the standard gross-to-net step, which is not the same
  arithmetic as adding: 15% of 3,000 is 450, but the VAT *inside* 3,000 is 391.30.
  Getting that backwards is a 15% error nobody notices for a season.
- **A charge is frozen onto the reservation**, like the price and the rate plan
  before it. Rule 4 covers a charge as much as a rate: raising VAT in March must
  leave February's invoices exactly as they were, and a charge a property later
  deletes must still be readable on the stays that paid it. `reservation_charges`
  carries everything needed to reprint the line, and `charge_id` is a link for
  reporting rather than the source of any number on it.

Two decisions the draft did not anticipate:

- **Charges follow the discount, and an override.** VAT is charged on what the guest
  actually pays, not on a list price nobody paid — so the base is the price after an
  offer, and after a lodge overrides it. An override therefore replaces what the
  *stay* costs rather than the final total; taxes and fees follow the number the
  lodge typed exactly as they follow the number the calendar produced.
- **No tax on tax, unless a lodge says so.** Every charge works from the stay by
  default, so two percentages cannot compound by accident. A charge set to "the stay
  plus the charges above" works from everything sorted before it, which is how VAT
  sits on top of a tourism levy that is passed on to the guest. The order is the sort
  order the lodge already sees on its own screen; nothing infers it.

`reservations.charges_amount` holds what was added on top, so that
quoted − discount + charges = total reads as the total on every screen, with each
part named — on the stay detail, in the booking form's preview while a price is being
read out to a guest, and in both confirmation emails.

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

- `bookable_unit_calendar_days` **keeps the counters** — `units_total`, `units_sold`,
  `units_blocked` — per room type per night. Inventory is shared across rate plans.
  The concurrency mechanism and its process-forking test stay untouched.
- `rate_plan_days` is new — `rate_plan_id`, `bookable_unit_id`, `date`, `rate`,
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
| `amenities` + `amenity_bookable_unit` | Amenity catalogue with categories, and what each room type has |

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

### Where the panel talks to the operator

**One notice board on the dashboard, and no strips above the screens.** The first
attempt at telling an operator something — that this property's booking mail is
being held rather than sent — was a coloured strip pinned above every page. Two
things were wrong with it: it took a slice off the top of the panel and fought the
sticky page header on every scroll, and a strip has no room for the sentence that
actually helps, which is *what to do about it*.

So notices live in a widget at the top of the dashboard
(`App\Filament\Partner\Support\PropertyNotices`), each with the screen that answers
it, and the panel gains a **Getting started** page — the three booking states, what
each does with the mail, a checklist that derives itself from the data, and the
address to write to. A notice must be **true right now, about this property, and
either actionable or explainable**, and it disappears by itself when its condition
stops holding.

The one thing a dashboard cannot do is warn somebody at the moment they act, so
where the confirmation went is also said in the notification after a booking is
saved — the point in the day when it matters, and nowhere else.

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

### Time inside a day — decided 2026-08-12, before any code

The brief in `BOOKING_BEYOND_ROOMS.md` §3.1 proposed a **slot** beside the date,
null for anything sold by the day. Reviewing it against what an activity
operator actually needs turned it from a flag into a small entity, and produced
two decisions that are expensive to get wrong.

**1. The grid is drawing; the slot is inventory.**

A quad tour at 09:00 with eight seats is *one* row with *one* counter, moved by
the same conditional `UPDATE` that resolves two people racing for the last room.
A 15-minute grid is how that row is *drawn* on an hour axis — it is not 96 rows
a day.

This is the decision the whole thing rests on. Putting the grid into the
inventory would give every unit hundreds of counters per day, and the atomic
counter — the single most valuable piece of this system, and the one
`BOOKING_BEYOND_ROOMS.md` §4 forbids replacing — would become an overlap query
under concurrency that nobody can reason about. The resolution a property draws
its day at is a **property of the screen**, configurable per operator (15, 30,
60 minutes), and it touches no table that counts anything.

**2. A slot carries a start and a duration, not a number.**

`slot_1`, `slot_2` would be enough to key a counter and useless for everything
else: a column has to know where on the axis it sits and how tall it is, and
"09:00 for three hours" beside "12:00 for three hours" has to be expressible
without anything guessing. So a slot is a start time and a length, and a
departure is `(unit, date, slot)` with `slot` null for everything sold by the
day. Accommodation rows are unchanged, and so is every query that reads them.

**3. Views are a reading of the same rows, not a second calendar.**

The calendar today is one fixed fortnight. It becomes day, week and month, with
a month and year to jump to. Underneath, the two verticals read the same table
from different directions:

| | Down the page | Across |
|---|---|---|
| A lodge, month view | room types | nights |
| A tour operator, day view | the hour axis at the property's own resolution | departures |

Whether that is one component transposed or two components is a question to
answer *while building it*, against a real grid — not to guess at now. What must
be true either way is that the rows underneath are the same rows, because the
moment they are not, a property that sells both a chalet and a sunset drive has
two calendars and no way to see its day.

**Answered, 2026-08-12: two components, one read model.** Built against a real
grid, the transposition turned out to be the wrong shape, and for a reason worth
keeping.

A night grid's second axis is a **series of counters**. Every (unit, night) pair
has one, every cell is populated, and a stay is drawn *over* those cells as a bar
spanning some of them. An hour axis carries no counters at all: it is a ruler,
the only thing on it is a departure block positioned against it, and a
fifteen-minute axis across eight departures is 96 × 8 positions of which eight
mean anything. Transposing the one component would therefore have to invent an
empty cell for every (time step, departure) pair — which is decision 1 above,
*the grid is drawing, the slot is inventory*, re-committed one abstraction down
in the view layer. Going the other way is no better: making a departure "a cell
with a span" puts a concept into the night grid that no night has.

So `OccupancyGrid` and `DayGrid` are two builders and
`occupancy-grid.blade.php` / `departure-grid.blade.php` two partials — but they
read the same table through the same sparse-calendar rules on
`CalendarSnapshot`, which is the part the decision above actually insisted on. A
property that sells a chalet and a sunset drive sees one calendar twice on one
screen, not two calendars.

**Where it stands.** The data model is built (2026-08-12): `booking_slots` is the
timetable a unit runs, `bookable_unit_calendar_days.slot_id` keys a row to a
departure, and the uniqueness rule is two partial indexes — one row per unit per
night where there is no slot, one per unit per date per departure where there is.
Two indexes rather than one over three columns because SQL treats NULLs as
distinct, and a single index would have let a lodge keep two counters for the
same night: the exact failure the counter exists to prevent, arriving silently.
Selling one works the same day: a `BookingLine` carries an optional slot, the
counter is keyed to it, and cancelling gives the seats back to the departure that
holds them rather than to the day beside it. The departure is frozen onto the
stay (`reservation_units.slot_id` + `slot_label`) for the same reason the rate
plan's name is — renaming "Morning departure" in March must not change what
February sold.

A rate per departure works the same way and for the same reason: `rate_plan_days`
gained the slot, with the same two partial indexes, and the lookup is three steps
that all already existed — the departure's own rate, else the day's, else the
unit's. A sunset drive can cost more than the morning one.

**The screen, built 2026-08-12.** The calendar stopped being a fixed fortnight:
it is a day, a week or a month (`App\Enums\CalendarRange`), with a month and a
year to jump to.

Ranges **snap** — a month runs from the 1st to the last, a week from Monday, and
the arrows move a whole one at a time. The alternative, a rolling thirty days
from wherever you happen to be, cannot survive a jump control: a range labelled
"September" that runs 12 September to 11 October is a lie, and two Septembers
compared that way are two different windows. The cost is real and named rather
than hidden: opening the calendar on the 28th shows three days ahead, which is
what the week view and one press of "Later" are for.

In the day view a property that runs departures gets the hour axis underneath
its nights, at a resolution **derived from its own timetable** — the coarsest of
60/30/15 minutes that still lands every departure's start and end on a line — and
overridable on screen. Nothing to configure, and nothing stored: the resolution
is a property of the screen, as decision 1 says.

Two things the build decided that the design had not:

- **A week and a month sum the day's departures.** A unit sold by departure has
  its counters on the departures' rows and never writes the row beside them, so
  a month view reading nights would have said "8 free" on every day of a fully
  booked season. Its cell counts the day's seats instead — capacity, sold and
  free summed across the timetable, priced at the cheapest seat, marked with the
  number of departures it came from. The detail of which departure sold what is
  the day view's, because drawing each seat sale as a bar makes a row as many
  lanes tall as the month has bookings.
- **A departure day's cell does not start a booking.** Every other free cell on
  the calendar opens the booking form on that room and that night; a departure
  has no night to sell, and a click that quietly moved the property's own
  counter instead of the 09:00 tour's would put the calendar wrong in exactly
  the way this design exists to prevent. On the *month* view that is still
  true — the cell there is a day of departures summed, and there is no one
  departure to sell. On the day view it is now the block itself that sells;
  see below.

**A timetable, and a seat sold on it — 2026-08-12.** The two halves of the
same slice, because either alone leaves the feature unreachable: a departure
nobody can enter is a screen nobody sees, and a departure nobody can sell is a
screen nobody uses.

The timetable is entered **inside the unit that runs it**, as a collapsed
"Departures" section on the room type — not a nav item of its own. A departure
has no meaning apart from the thing it departs, and a heading called
"Departures" in the sidebar is one more thing every lodge in the panel has to
read past. A property selling nights leaves the section closed and empty
forever, which is the rule the whole design is judged by.

Selling one: the day view's block carries a "+ Seat" that opens the booking
form already knowing the unit, the date and the departure, and the form itself
grows one field — **"Which departure"**, visible only where the unit has any,
and required there. Required, because leaving it blank on a unit that runs
departures takes the seat off the property's own counter instead: a different
pool, and the calendar would go on offering a tour that is full. A lodge is
never shown the field and never learns it exists.

Three decisions the build made that are worth stating:

- **Stay restrictions do not reach a departure.** A minimum stay and a
  closed-to-arrival day are rules about selling a *night*; a seat on the 09:00
  ride is not one, and a lodge's three-night minimum on the same property has
  no business refusing it. Skipped in the preview and in `InventoryWriter`
  alike — availability is still enforced, exactly as it is for a night.
- **Two rows of one unit on two departures are two lines.** Rows that say
  nothing about who is in the room are merged, because "2 standard" twice means
  four rooms. Two departures are two pools of seats, so the merge key gained
  the departure — merged, a booking for two on each tour would hold four on one.
- **Deleting a departure that has been sold is refused, on the model.**
  `bookable_unit_calendar_days.slot_id` and `rate_plan_days.slot_id` both cascade,
  so a delete reaches the counters through the database — below Eloquent, past
  `InventoryWriteGuard`, and with nothing to restore from. The rule lives on
  `BookingSlot` rather than on a screen because the timetable is editable from
  more than one panel, and a rule only one of them knows is not a rule. An
  empty departure still deletes; a sold one is switched off instead.

The words changed with the thing being sold, because the screen made it obvious:
a room line on a tour says **Seats** rather than Units, the preview counts
**days** rather than nights when every line is a departure, and the field is
"Which departure" rather than "Departure" — the booking form already had a
field by that name, and it is the check-out date.

**The morning board — 2026-08-12.** The arrivals board was night-shaped, and for
a seat that produced a plausible lie: a booking for three places on the morning
ride read as *3 rooms arriving*, a number a desk lays tables from.

It now carries a **passenger list per departure** — time order down the page,
one section each so a page breaks between departures and a guide can be handed
the sheet for their own vehicle, with the seats, the party, the status and the
phone number. The phone is the reason the sheet leaves the office at all:
somebody is not at the vehicle and there is five minutes to find out why.

The manifests come from `DayGrid`, which is the calendar's own answer to what
departs today and how full it is. One definition read twice, so a list carried
to a vehicle and the grid on the office wall cannot disagree about who is on the
09:00.

Three rules, each of which is the board telling the truth rather than a feature:

- **A booking made only of seats is not an arrival.** Nobody checks in, nobody
  is given a key, and it is on its manifest and nowhere else. A booking holding
  a chalet *and* a ride is on both — the guest really does arrive — but it
  counts as the one room it holds. `ArrivalsBoardData::units()` ignores seat
  lines for the same reason: three seats are not three rooms, and the room
  count is what a kitchen lays tables from.
- **A departure nobody booked is not a page.** It is on the calendar, where an
  empty seat count is the entire point; on a passenger list it is a sheet of
  nothing.
- **An operator who sells nothing by the night is not asked about rooms.**
  Arriving / departing / staying-on come off the screen entirely rather than
  printing three headings that say "nobody" — that is not a board, it is
  somebody else's board with the rows taken out.

**A guard test, because the risk here is a leak and not a bug.**
`AccommodationUnchangedByTimeTest` asserts the thing the whole section rests on:
a property that sells nights does not notice that departures exist. It is the
same kind of test as the one that sat on the two availability readers before
they were joined, and for the same reason — a departure's row shares a table, a
date and a unit with a night's row, so every reader that forgets to say which of
the two it wants gets a *plausible* number back, and nobody looks twice at a
calendar that reads eight free.

It started red, which is the point. `AvailabilityCalendar::snapshot()` — the bulk
read the whole occupancy grid is built on — fetched both kinds of row and keyed
them by date alone, so whichever the database returned last silently became the
day; `rateDaysKeyedByDate()`, which prices a stay, did the same. Both are fixed,
and the route the leak actually reaches a night by is now pinned: an operator
retires a departure, the unit goes back to being sold by the period, and last
month's 09:00 tour must not start answering for the night.

**What none of this changes, which is the point.** Every rate a lodge has ever
entered is a null-slot row; every row it will enter stays one; every query that
reads a night reads it unchanged. The concurrency guarantee is asserted at both
levels — the last room of a night and the last seat of a departure — by the same
forking test against the same conditional `UPDATE`, because it is the same
mechanism and not a second one.

**What this does not change.** Inventory stays a counter per unit per period.
Restrictions stay where they are. The price stays frozen. A stay crossing
midnight is still one row per period, not a range with overlap arithmetic —
minutes are a scheduling problem, and this is an inventory system.

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

**7. The customer, and the bridge from a request to a stay. — Done, 2026-08-11.**
Raised in review as the largest remaining gap, and it was: a booking system has a
small number of main entities and the customer is one of them, and this one went six
slices without it. `reservations` carried a name, an email and a phone as free text,
so "this guest's last three stays" could not be asked at all.

The requirement behind it, in its own words, because it is not only about one
screen: **somebody at a reception desk has to be able to reach the same data by
whatever route they happen to be on.** By date on the calendar, by arrival on the
board, by reference, by name, by phone number. A system with one path to each fact
is a system that gets abandoned for a notebook. So the customer screen is a search,
a history and a place for notes, and the stay detail links back the other way.

The decisions, each of which is expensive to change later:

- **"Customer", not "guest".** The same system is sold to quad tour operators and
  car rental companies, where nobody is a guest — and `guest` is already taken in
  this schema, twice, with different meanings (`reservation_guests` is a count per
  room, `guest_categories` are age bands).
- **Scoped to the partner, not global.** Two lodges hosting the same traveller each
  keep their own record with their own notes. One operator's note is not the
  other's to read, and a shared address book is not what a partner agreed to. A
  partner's own properties do share — NWR is one partner with twenty camps.
- **Matched by `user_id` first, then email.** An account survives a change of
  address; an email does not. This is also what closes the circle from the
  traveller-facing site: somebody who signs in on namibway.com and books arrives
  here already identified.
- **The phone is not a match key.** It is stored normalised and searched on, but a
  couple's single mobile silently merging two people is much harder to undo than two
  records. A human decides.
- **Every booking resolves a customer**, inside `InventoryWriter::book()` rather
  than in each screen, so the customer view has no holes in it.
- **An existing customer is used, not overwritten.** A typo in one booking must not
  rewrite what a property learned over three seasons. Correcting a customer is its
  own act on its own screen.
- **Email is required on the website and optional at the desk.** A mandatory field
  that cannot always be satisfied produces `x@x.com`, which is worse than a blank.

Comments are a polymorphic `notes` table with the author's name frozen beside the
account id — on customers and stays alike. `reservations.notes` stays and is a
different thing: that column is what the booking was taken with, the table is the
running log that starts afterwards.

The same step built the **`Inquiry` → `Reservation` bridge** (`StayPromoter`), which
had been designed in `CLAUDE.md` and left unbuilt. A confirmed request now becomes a
stay that holds real inventory and appears on the arrivals board, once, idempotently
— and a promotion that cannot happen alerts the team instead of un-confirming a
guest who has already been told they have a room.

**8. Taxes, levies and fees. — Done, 2026-08-12.** The layer §3 reserved, built as
designed: charges apply to a finished price, are frozen onto the stay, and are the
one part of the chain that may not reach backwards. What changed against the draft
is written up there rather than here — in short, included-or-added turned out to be
a question about the charge rather than about the property, and charges follow a
discount and an override because tax is charged on what is actually paid.

A lodge that charges nothing sees nothing: no charge rows means no breakdown, no
extra line on the invoice, and a total identical to the one it had before this step.

Steps 5 and 6 were the other way round in the first draft. Switching a lodge on is
a rollout feature rather than a booking-core one, but it is what turns all of this
into something a real partner uses, and amenities have no dependency on anything
here at all.

---

### Printing the arrivals board printed the menu — found 2026-08-12, fixed the same day

Printing the arrivals view produced the navigation and none of the board.

The print rules that existed were the ones this codebase wrote — `nw-noprint`,
the calendar viewport's ceiling coming off, table rows not breaking across pages
(`lodge-styles.blade.php`). They all assume the *page* prints and only some
parts of it need hiding. What they never handled is Filament's own shell: the
sidebar and topbar are laid out as fixed/positioned elements and the main
content sits in a scrolling container, so on paper the shell is what has a
position and the content is what gets clipped.

Fixed as designed — one print stylesheet on the panel
(`filament.partner.partials.print-styles`, a `HEAD_END` render hook), not on the
board, because every screen a property prints has the same problem. A printed
passenger list is carried to a vehicle and a printed calendar goes on the office
wall.

Two things the fix turned up that the diagnosis had not:

- **The page header is sticky in this panel** (`sticky-page-header`, so header
  actions stay put while a long form scrolls), and a sticky element on paper is
  one that has left the flow. It printed *on top of* the first line of the
  board, which is where the date was. A passenger list with no date on it is
  worse than no sheet, and nothing about it was visible on screen — which is the
  argument for looking at the print, not only at the page.
- **A date that only exists next to the arrows does not print**, because the
  arrows do not. The calendar's range now also renders as a `nw-printonly`
  heading, so what comes off the printer says which week it is.

---

### Capacity was a filter, not a rule — found 2026-08-12, fixed the same day

Reported from the panel: a room for 2 adults and 2 children took a booking for
2 adults and **3 children** without an error or a warning.

The diagnosis, because it is not what it looks like. Capacity is enforced in
exactly one place and it is not a rule there either — it is a *filter*:
`RoomAvailability::seats()` decides which rooms the trip plan **offers**, and it
gets the arithmetic right, including letting a child take a spare adult slot. But:

- **`ListingController::storeInquiry` validates nothing about it.** `adults` and
  `children` are checked as integers 1–20 and 0–20, and the chosen
  `bookable_unit_code` (then `room_type_code`) is never compared against the room it names. A party that
  grows after the room was picked, or a request posted directly, goes through.
- **`InventoryWriter::book()` never compares them at all.** `assertFits()` looks
  at the `Occupancy` object, so it only runs where a rate plan prices by guests;
  under a per-room tariff the header counts are recorded and never questioned.
  This is the path the desk uses, which is where it was found.

So nothing *accepts* a capacity rule; one screen merely declines to show you the
room.

The fix is not "refuse everywhere", and this is the part worth deciding before
building it. **At a desk, exceeding capacity is legitimate** — a cot goes in the
room, and a receptionist who is told "no" by software they cannot argue with
writes the booking on paper instead. **On the traveller-facing path it is not**,
because nobody is there to judge whether the room can take a fourth child.

So: refuse on the website (a validation rule against the named room type), and
at the desk warn with a reason the operator confirms — "sleeps 2 + 2, this is 2 +
3, extra bed?" — recorded on the stay like a price override is, so the
housekeeping list and the arrivals board know a cot is needed. That last part is
the reason to record it rather than merely allow it.

**Built as designed.** The arithmetic moved out of `RoomAvailability` into
`App\Services\Booking\RoomCapacity`, which owns both the sum and the sentence, so
the three places that now have an opinion share one answer and differ only in
what they *do* with it:

- the picker still declines to offer a room the party does not fit;
- `TripController::store` refuses one, before the trip is created and before an
  anonymous plan is claimed — a rejected request leaves no trace, which is the
  rule the one-active-request gate above it already follows;
- the desk is warned in the price block, and the answer it types
  (`reservations.over_capacity_note`) is kept and shown on the stay detail and
  on the arrivals board, which is the list a room is made up from.

Four decisions worth stating:

- **Capacity is asked of the whole booking, not of each room.** A stay holding
  two rooms under a per-room tariff has its guest counts on the header and
  nothing that says who is where; splitting them would be inventing an
  attribution, and the honest question is whether the party fits what was
  booked. Seven people in two doubles is over capacity however they arrange
  themselves.
- **`InventoryWriter` records the note and enforces nothing.** Whether a room
  may be overfilled is a question about *who is asking*, and the writer is
  neither the website nor a desk. Making it refuse would also break promoting a
  request taken before any of this existed.
- **Where a plan prices by guests, nothing changed.** Those lines carry their own
  people and `AvailabilityCalendar::assertFits` already refuses a line it cannot
  price — a hard refusal, since there is no price to compute. The header-count
  warning is skipped there rather than saying it twice in two voices.
- **A room type with no capacity entered refuses nobody.** Blank is not zero, and
  refusing every booking of a half-set-up room would be worse than not checking.

---

## 7. Deliberately not in this plan

Naming these matters as much as the plan, because a disabled button is a claim:

Channel synchronisation, iCal, room-level assignment (a reservation holds room
types and quantities, never a named room), housekeeping, and tax reporting.

**Folio and payments used to be on that list, came off it on 2026-08-12, and were
built the same day.** A booking system that cannot say whether a stay has been
paid is not one, and this one could not: the reservation carried the whole debit
side (`total_amount`, `charges_amount`, `discount_amount`, `currency`) and there
was no credit side at all. There is now — `payments` holds one row per movement of
money, `reservations` carries `paid_amount` and `payment_status` as stored
results, invoices have numbers, and all six slices of `PAYMENTS_BUILD.md` are
worked through, including the three settlement models we offer partners rather
than picking one. The design is `PAYMENTS.md`; what it still describes and nobody
has built is §6 step 6, **payouts and partner statements**, which wants real money
to have moved before it can be tested. `DpoProvider` is implemented and has never
run against a real merchant account. The rules that must survive a later change to
any of it are collected in `CLAUDE.md` → "The money side".

Channel synchronisation deserves one word of precision, because §8 looks like it
contradicts this line and does not: what is out of scope is *us* pushing rates
and availability into each channel's system. An API that lets an authorised
outside system read our calendar and book against it points the other way, and
is very much in the plan.

One thing is now *half* here and should be named rather than assumed. Since
2026-08-12 the trip plan prices from the property's own calendar and rate plan,
taxes included (`App\Services\Booking\RoomOffers`), and availability is the
smaller of two counts: what the calendar has free, and what is left after the
requests already asking for the same nights. So a stay the lodge took at its own
desk stops being offered online, and a season the lodge priced reaches the
traveller.

The last piece landed the same day: **a request holds its room.** A request on a
property whose inventory we hold takes a provisional stay on the calendar
(`StayPromoter::hold`), released when it is declined or expires and transitioned
into the guest's own stay when it is confirmed. The second count now covers only
what could take no hold — a partner on somebody else's PMS, a request with no
dates — and a request that holds its room is excluded from it precisely because
it holds it, so the two can never double-count.

Also worth repeating: **no connector has ever run against a real partner account.**
The market conventions this design follows are well established, but the data
models of ResRequest, NightsBridge and hopeCloud have not been seen from the
inside. The first real integration will produce surprises, and the pluggable
strategy is what makes absorbing them cheap.

---

## 8. The API is not a side door — it is the second front door

**Decision:** the booking system has to be fully operable over the API, at
`api.namibway.com`. Everything a lodge can do at its own desk, and everything a
traveller can do on namibway.com, an authorised external system must be able to
do too: read the calendar, read rates, take a booking, hold it, change it,
cancel it. The point is that an OTA — Expedia, Booking.com, a DMC's own
mid-office, a partner's website built on something else entirely — can sell a
property that runs on our system without us writing a bespoke integration each
time.

This is the same discipline the panel already follows and should be read that
way: **one definition, read more than once.** The night grid and the hour grid
are two components over one read model, not two systems; the API is a third
reader over the same one. It is emphatically *not* a parallel booking path with
its own availability rules — if the API can create a stay the desk could not, or
prices a night differently, then the calendar has stopped being the truth and
the whole point of §2 is gone.

### Not the same thing as channel synchronisation

§7 lists channel synchronisation as deliberately out of scope, and that stays
true — the two point in opposite directions and are easy to confuse:

- **Channel sync (out):** *we* push our availability and rates into somebody
  else's system, and consume their bookings. That means one connector per
  channel, each with its own mapping, retry semantics and reconciliation.
- **This (in):** *they* call us. One documented interface, versioned, that we
  own. A new channel costs us a client record and a token, not a codebase.

The second is a product feature of the booking system; the first is an
integration project per counterparty. Doing the second well makes the first
merely optional.

### What exists today — the honest inventory

`/api/v1` (`routes/api.php`) is **three read-only endpoints** behind Sanctum +
`EnsureApiClientActive` + `throttle:api`, documented with Scribe:

- `GET listings` — published listings, filtered like Explore.
- `GET listings/{slug}` — one listing.
- `GET listings/{slug}/availability` — and this one is the important
  disappointment. It does **not** read our ARI calendar. It resolves the
  listing's partner, proxies the *partner's own connector* (ResConnect,
  NightsBridge, hopeCloud, NWR-concierge, Native), and where there is no booking
  connector it answers `{"live_availability": false, "booking_mode": "inquiry"}`.
  So for exactly the properties whose inventory we hold and price ourselves, the
  public API is the least informed reader in the building — the trip plan
  (`RoomOffers`) and the partner panel both see the real calendar, and the API
  sees whatever a third-party PMS says, or nothing.

Everything else is missing. There is no endpoint that returns rates, takes a
booking, holds inventory, amends or cancels a stay, or reads a reservation back.
`api.namibway.com` does not exist as a host — the only host split configured is
the partner panel's (`config('booking.panel_domain')`, still unset in
production). And `ApiClient` carries `name`, `contact_email`, `is_active` and
nothing else: no partner scope, no abilities. A token is all-or-nothing over
every published listing, which is adequate for reading a public catalogue and
nowhere near adequate for writing bookings.

### What it implies, so nothing gets built that has to be torn up

None of this is new architecture. It is the existing rules, restated for a caller
who is not a browser:

- **The write path does not fork.** An API booking goes through
  `InventoryWriter` like every other write — `InventoryWriteGuard` and the
  architecture test already make any other route a test failure, and that must
  stay true when the caller is Expedia.
- **Creation must be idempotent.** A network timeout on somebody else's side
  must not produce two stays. `reservations.inquiry_id` is unique for exactly
  this reason on the inquiry path; the API needs its own key — a client-supplied
  reference, unique per client — and a repeat of the same call must return the
  same reservation rather than a second one.
- **Availability is a conditional `UPDATE`, not a read-then-write.** An OTA is a
  concurrent seller by definition; the atomic decrement in §2 is what makes that
  safe, and a "check then book" API shape would quietly undo it.
- **The price is a stored result, not a promise to recompute.** A quote returned
  to a channel and the booking that follows have to agree, so a quote needs an
  identity and a lifetime, and the booking references it. This is the same reason
  `total_amount` is frozen on the reservation.
- **Half-open intervals and ISO codes at the boundary too** — dates as
  `YYYY-MM-DD` with checkout exclusive, ISO 4217 currency, ISO 3166-1 alpha-2
  countries. Every counterparty already speaks these; inventing anything here
  buys nothing and costs every integration a paragraph of explanation.
- **A departure is a first-class thing.** `(unit, date, slot)` with a null slot
  meaning "sold by the night" is our model since 2026-08-12, and a seat-selling
  operator is precisely the kind of supplier an activity marketplace wants. The
  API must express it, not flatten it back into nights.
- **Scoping is per partner, and writing is a separate ability from reading.** A
  channel selling one lodge must not be able to read another's calendar, let
  alone book it. That is a real change to `ApiClient` (a partner relationship and
  token abilities), not a middleware tweak.

### Open, and deliberately not decided here

- **Push or pull.** Large OTAs prefer to hold a cached copy and be notified when
  it goes stale (ARI push / webhooks) over polling us per search. Pull is where
  this starts because it is what a documented read API already almost is; a
  webhook side needs its own delivery, retry and replay story and should be
  designed when a counterparty exists, not before.
- **Our own dialect or an established one.** §"Standards" in `CLAUDE.md` says use
  the industry standard where a partner system already speaks it. For hotel
  distribution that means OTA/OpenTravel (verbose XML, but what the big channels
  actually consume) versus a clean JSON API that fits our model and needs a
  mapping layer per big channel. This is the biggest single decision in the
  section and it is genuinely open — worth deciding against a real counterparty's
  documentation rather than in the abstract.
- **How commission and payment ride along.** A booking taken through a channel
  has a different money path than one taken on namibway.com. **Updated
  2026-08-12:** there is a payments model now, and it already answers half of
  this — commission and deposit resolve per listing and partner, and who collects
  is derived from the deposit share rather than stored. What it does not answer is
  a *third* party in the chain: an outside seller taking its own margin is neither
  us nor the property, and `SettlementBalance` knows about two sides only.

`api.namibway.com` itself is server-side and outside the application — a DNS
record at OVH (namibway.com's DNS is not at Cloudflare), a certificate, an nginx
server block — the same prerequisite list as the partner panel's host in
`config/booking.php`.

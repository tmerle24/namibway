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

## 7. Deliberately not in this plan

Naming these matters as much as the plan, because a disabled button is a claim:

Channel synchronisation, iCal, room-level assignment (a reservation holds room
types and quantities, never a named room), folio and payments, housekeeping, and
tax reporting.

One thing is now *half* here and should be named rather than assumed. Since
2026-08-12 the trip plan prices from the property's own calendar and rate plan,
taxes included (`App\Services\Booking\RoomOffers`), and availability is the
smaller of two counts: what the calendar has free, and what is left after the
requests already asking for the same nights. So a stay the lodge took at its own
desk stops being offered online, and a season the lodge priced reaches the
traveller.

What is left is deliberate: **a request in flight holds no ARI inventory**,
because holding real rooms for every question is the flooding problem this whole
mechanic exists to prevent. Writing soft holds into the calendar as real
inventory — a held reservation, released by the same expiry job that exists
today — is the step that would leave exactly one count and close the
`StayPromoter` alert for good. It is the last piece of this seam and it is not
built.

Also worth repeating: **no connector has ever run against a real partner account.**
The market conventions this design follows are well established, but the data
models of ResRequest, NightsBridge and hopeCloud have not been seen from the
inside. The first real integration will produce surprises, and the pluggable
strategy is what makes absorbing them cheap.

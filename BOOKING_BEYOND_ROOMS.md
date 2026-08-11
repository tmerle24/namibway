# Brief: the booking system beyond rooms

**Written 2026-08-11.** A working brief for taking the lodge booking system —
`BOOKING_SYSTEM.md`, steps 1–6, live — and selling it to activity operators (quad
tours, scenic flights, guided drives) and car rental companies.

Read `BOOKING_SYSTEM.md` first, in particular §2 "The five rules". This brief is
written on the assumption that those five rules hold unchanged; if any of them has
to bend, that is the finding, and it goes in the design document before any code.

---

## 1. What the task actually is

Not "build a booking system for tours". The booking system exists. The task is to
find every place where it quietly assumes **a room, sold by the night**, and decide
one of three things for each:

1. it generalises as it stands and only the wording is wrong,
2. it needs one new dimension, additive, or
3. it is genuinely accommodation-specific and belongs at the edge rather than in the
   core.

The value of the work is in that third pile being small and honest. A core that
grows a flag for every vertical is how this ends up unmaintainable — which is the
failure `BOOKING_SYSTEM.md` §1 was written to avoid.

## 2. What is already there, and is more than it looks

Do not start by adding tables. The platform already models these verticals:

- `ListingType`: `accommodation`, `activity`, `restaurant`, `vehicle` — every listing
  already declares which it is.
- `VehicleCategory` (`self_drive` / `guided_tour`) and `VehicleClass` (sedan, SUV,
  4x4 camper, motorhome, minibus) already exist.
- `PriceUnit` already covers `per_day`, `per_person`, `per_booking` beside the
  nightly ones, and `listings.duration_minutes` exists for things that take hours
  rather than nights.

So the traveller-facing half of the platform has understood these verticals for a
while. What has never been connected to them is the **booking core**, which was
built accommodation-first and is still called that everywhere.

## 3. The questions to answer, with a recommendation each

These are the decisions. Each one is a place where the wrong answer is expensive
later, so each gets argued in the design document before it gets built.

### 3.1 Does the calendar stay keyed by date?

Today: `room_type_calendar_days` is one row per unit per **date**, and availability
moves by a conditional `UPDATE` on a counter. That mechanism — and its
process-forking test — is the single most valuable thing in the system and the one
piece that must not be re-solved.

A quad tour is not a date. It is a **departure**: 09:00, three hours, eight seats.
Two departures on one day are two separate pools of seats.

**Recommendation:** keep the date as the key and add an optional **slot** beside it,
so a row is keyed `(unit, date, slot)` with `slot` null for anything sold by the
day. Accommodation rows are unchanged, the conditional `UPDATE` is unchanged, and a
tour operator gets real per-departure inventory. Resist the temptation to model
time as a timestamp range with overlap queries: that replaces an atomic counter
with a query nobody can reason about under concurrency, for a gain nobody asked for.

**Decided 2026-08-12, with two refinements** — see `BOOKING_SYSTEM.md`, "Time
inside a day", which is now the authoritative version:

- A slot carries a **start and a duration**, not an index. A column has to know
  where on the axis it sits and how tall it is.
- The **drawing resolution** (15/30/60 minutes) is a property of the screen and
  configurable per operator. It never reaches a table that counts anything: one
  departure is one row with one counter, not 96 rows a day.

### 3.2 What is the sellable thing called?

`room_types` is the wrong name for a quad bike, a seat and a Land Cruiser. Renaming
a live table is a real cost — but there is exactly one partner, no live bookings,
and the cost will never be lower than it is now.

**Recommendation:** rename to something vertical-neutral (`bookable_units` or
`inventory_types`) in one migration, with the model, the relations and the screens
following. Do it before the first real partner, or accept living with the word
forever. Say which you chose and why, either way.

### 3.3 Half-open intervals — do they survive car rental?

Accommodation's rule is that a stay ending on the day another begins does not
overlap it: the room turns over. Car rental has exactly the same shape — a car
returned Tuesday can go out again Tuesday — so the rule survives, which is a
pleasant surprise worth checking rather than assuming.

What does **not** survive is that the times matter: a car returned at 17:00 cannot
realistically go out at 17:30, because it has to be cleaned and refuelled.

**Recommendation:** keep the inventory at day granularity, and give the operator a
**turnaround buffer in days** (usually 0, sometimes 1) rather than modelling
minutes. Store pickup and return *times* as attributes of the booking for the desk
to work from. A rental yard's real scheduling problem is not one this system should
pretend to solve.

### 3.4 How much of the pricing survives?

More than you would expect, and this is the part to verify rather than rebuild:

- **Per-occupancy pricing** already prices "1 guest, 2 guests, 3 guests" — which is
  exactly a tour sold per seat, and a car sold by the number of people it must
  carry.
- **Per-person-sharing** is accommodation-shaped and simply will not be chosen by a
  tour operator. That is fine; strategies are opt-in per rate plan.
- **Rate plans** generalise cleanly: what a tour "includes" (transfer, lunch,
  park fees) is the same idea as a board basis, and eligibility (resident, SADC,
  agent) is if anything more common in activities than in lodging.
- **Board basis** is accommodation-only. It should become one attribute of a rate
  plan among several rather than the only one — probably a small "what's included"
  list, which is the same problem the amenity catalogue already solved.
- **Promotions** are vertical-neutral as built, except `free_nights`, which needs a
  sibling or a rename ("stay 4, pay 3" is "book 4 days, pay 3" for a rental).

Check each of these against the code rather than against this list.

### 3.5 What is genuinely per-vertical, and must stay at the edge?

Named here so it does not creep into the core:

- **Car rental:** driver's name and licence, second driver, a deposit or excess,
  pickup and return branch, one-way fees, insurance option, fuel policy.
- **Tours:** participant names, weight limits (real for quad bikes and microlights),
  age minimums, pickup point and time, what to bring.
- **Accommodation:** board basis, bed configuration, the occupancy machinery.

These belong beside the reservation, not inside it. The pattern already in the
codebase for exactly this is `Partner.connector_config` — a typed value object over
a JSON column, read through a class so "JSON" never means "anything goes" at the
point of use. `App\Services\Pricing\PricingConfig` is the worked example.

### 3.6 What does a "night" become in the frozen record?

`reservation_nights` is the frozen result and the thing every invoice and report
reads. For a tour it is one row; for a rental it is one row per day.

**Recommendation:** keep the table and the shape, rename the concept in the
documentation and the screens, and do **not** invent a second results table. One
frozen per-period amount is the hourglass's waist, and putting a second one beside
it would undo the whole design.

## 4. Constraints — what this work may not do

Straight from `BOOKING_SYSTEM.md` §2, restated as things to check yourself against:

1. Inventory stays physical, counted per unit per period, never per rate plan.
2. A rate plan stays a product, not the pricing engine.
3. A pricing strategy computes and never touches inventory.
4. The price is frozen at booking; nothing recomputes it.
5. A new pricing strategy — or a new vertical's pricing — is an extension, not a
   schema change.

Plus one from this brief: **the conditional `UPDATE` on a counter is not to be
replaced.** Everything else is negotiable.

## 5. Order of work

Each step leaves the system working and shippable, the same discipline as the six
steps before it.

1. **Survey and decide.** Read the core for accommodation assumptions; write the
   answers to §3 into `BOOKING_SYSTEM.md` with the reasoning. No code. This step is
   the one most likely to be skipped and the one most likely to be regretted.
2. **Rename the sellable unit**, if §3.2 says so. One migration, mechanical,
   entirely reversible while nothing is live.
3. **Slots.** The optional slot dimension on the calendar and the writer, with a
   concurrency test that fires two bookings at the last seat of one departure — the
   existing process-forking test is the template.
4. **A vertical's own attributes**, starting with whichever partner is closest to
   signing. Typed config over JSON, at the edge.
5. **The screens.** The calendar is room types down and nights across; a tour
   operator wants the hour axis down and departures across, at a resolution they
   choose. Find out whether it really is the same component transposed before
   writing a second one — but the rows underneath must be the same rows either
   way, or a property selling both a chalet and a sunset drive ends up with two
   calendars and no way to see its day. The fixed fortnight also becomes day,
   week and month, with a month and year to jump to.

## 6. What to be suspicious of

- **A flag called `is_activity`.** If the core needs to know which vertical it is
  serving, the generalisation has failed and the design document should say so
  rather than the code hiding it.
- **Renaming as the whole job.** If the work turns out to be only wording, that is a
  finding worth stating out loud — and it would be good news.
- **Modelling time too finely.** Minutes are a scheduling problem. This is an
  inventory system.
- **A second results table.** See §3.6.

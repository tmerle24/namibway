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

> **Done 2026-08-12: `bookable_units`.** `room_types` → `bookable_units`,
> `RoomType` → `BookableUnit`, every `room_type_id` → `bookable_unit_id`,
> `room_type_calendar_days` → `bookable_unit_calendar_days`, both pivots, and
> `inquiries.room_type_code` → `bookable_unit_code`. One migration, reversible
> both ways including the twenty-five index and constraint names Postgres would
> otherwise have left pointing at a table that no longer exists.
>
> **Why `bookable_units` and not `inventory_types`.** It reads as English in the
> sentences this code actually writes, and it is the word the codebase had already
> drifted to on its own — `BookingSlot::forUnit()`, `DayGrid`'s `$unit`, the
> exporter's own docblock — while the table still said "room type". The wart it
> carries is named rather than hidden: `bookable_units.total_units` is now "how
> many units of this unit", and `reservation_units` is a third use of the word.
> Chasing that would mean renaming two more tables for a word that is repeated
> rather than wrong.
>
> **What kept the old word, and why.** The rename stopped at every boundary where
> "room type" is somebody else's word or the correct one: the connector DTOs and
> the `room_types` key in the documented `/api/v1` response (the partner's
> vocabulary, in a mapping layer — ResRequest, NightsBridge and hopeCloud all say
> it); the `/listings/{slug}/room-types` endpoint and its picker (traveller-facing,
> about a lodge); the `RoomTypes` sheet in the bulk-capture workbook and its column
> headers (an interface with a person, who has those files already); the
> `room-types/` prefix on R2, which addresses objects that exist; and "Room type"
> as a label in both panels. What a tour operator should read there instead is
> §3.5's question, not this one.
>
> The cost was one afternoon and 126 files. The brief was right that it would
> never be cheaper: production had no rows in the table at all.

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

---

## 7. Decisions taken 2026-08-17

Step 1 of § 5 — "survey and decide, no code" — worked through in conversation. What follows
is what was settled, with the reasoning, so the build has something to be checked against.
Everything here is a decision; nothing here is built yet unless it says so.

### 7.1 The traveller-facing gap is one route, not the core

The core already carries every vertical: `BookableUnit` (renamed from `RoomType` on
2026-08-12), the ARI calendar keyed by date, `BookingSlot` for time inside a day, rate plans
with `PricingStrategy` (`per_unit`, `per_occupancy`, `per_person_sharing`) and
`RatePlanGuestAmount` for age categories. A quad tour, a rental car, a room and a table are
all expressible today, and all four are sellable from the partner panel.

What is **not** expressible is offering any of them to a traveller.
`GET /listings/{slug}/room-types` requires `check_in` *and* `check_out` and answers with
`rooms` and `price_per_night`. A tour has no departure date and no nightly price, so it
cannot pass through. `BookingSlot` appears zero times in `app/Http` and zero times in
`resources/js`.

**Decision: one endpoint for every vertical, not three.** The caller — Kaia, the listing
page, a customer's own website — must not have to know which vertical it is serving. That is
the same rule `InquiryKind` follows and the same warning § 6 gives about `is_activity`.

```
GET /availability?listing=…&check_in=…[&check_out=…][&time=…]&adults=…[&children=…]
```

| Question being asked | check_in | check_out | time | guests |
|---|---|---|---|---|
| Is a room free from–to for N people? | ✔ | ✔ | — | ✔ |
| Is an activity possible on X at Y for N? | ✔ | — | filter only | ✔ |
| Is a table free on X at Y for N? | ✔ | — | ✔ | ✔ |

**This is a deliberate deviation from the integration standards, and here is why.**
OpenTravel and the channel managers do the opposite — one message per domain
(`OTA_HotelAvailRQ` and its siblings), so three endpoints would be the more standard shape.
Those standards govern traffic *between companies*, where each domain arrives with its own
vocabulary. Here the sellable thing is already one entity with one calendar and one rate-plan
mechanism, so three endpoints would be three doors into the same room and every caller would
have to guess which. The discipline that keeps this simple: **one response shape, in which
the differences are data and never branches** — an empty slot list rather than an activity
mode. The moment an `if (activity)` appears inside this endpoint, the generalisation has
failed and § 6 already says so.

**`check_in` is a date, not a datetime.** For a slot-bound product the client must be
*offered* the departures rather than state one, or nobody discovers that 09:00, 12:00 and
15:00 exist. The time belongs in the response. `time` on the way in is a filter ("from
15:00"), never a requirement.

The response is one shape for all of them: unit code and name, `duration_minutes`, capacity,
the available slots (empty for rooms and cars), the number of periods, and the **total**
with the basis that produced it — not a nightly figure the caller has to multiply.

A difference to know rather than to design away: on a **tour** the time is a choice from a
list; on a **table** it is today a free-form wish, because restaurants have no slots and
sittings with a cover limit are not built. Both fit the same response shape once they exist.

### 7.2 `rate_per_night` becomes `base_rate`, and the deviation gets written down

The column is named for nights on a unit that is now also a quad and a car. Rename to
`base_rate`. Not plain `rate`, which reads as a sibling of `rate_plans`; not `rack_rate`,
which carries "undiscounted published price" and is not what this is.

**The deviation from ARI is that the unit carries a price at all.** By the standard a room
type has no price — prices live entirely in rate plans and the calendar carries inventory and
restrictions. Our column is a convenience for a property that has entered no rate plan yet.
That is allowed under the rule in `CLAUDE.md` ("deviating is allowed, write down why"), and
this is the writing down: **`base_rate` is the fallback used only where no `rate_plan_day`
exists for that date, and a rate plan always wins.** Without that sentence there are two
price sources and nobody can say which one applies.

**No period column is needed on the unit.** It says how it is sold by itself: slots present
→ sold per departure; no slots → sold per date row. A hotel night and a rental day are the
same date row, which is why the core already carries car rental without anyone having built
it.

### 7.3 `listings.price_unit` is display only — an earlier claim withdrawn

It was asserted during this conversation that the price unit sitting on the listing rather
than on the unit was an architecture error, and that a lodge could therefore not sell rooms
per night and tours per person. **That is wrong and is withdrawn.** `listings.price_unit`
feeds the "from N$ X" line on the listing page. What actually prices a booking is
`PricingStrategy` on the **rate plan**, and a listing can have as many rate plans as it has
things to sell. A lodge selling bungalows `per_occupancy` and a safari drive
`per_person_sharing` needs nothing new.

### 7.4 Vehicles: hand-over times, and what not to build

Required, and standard for car rental: **pickup and return time are entered on the booking
and reach the operator.** Two further pieces, deliberately separated because they are
different problems:

* **Hand-over hours** are a business-hours question, not an inventory one — a yard open
  08:00–17:00 weekdays and to 12:00 on Saturday. "Not bookable at 19:00" means the time is
  not offered, not that the calendar is full.
* **Turnaround buffer in days** on the unit (usually 0, sometimes 1): the car is not back on
  sale for N days after return.

**Not to be built: minute-level yard scheduling.** A car returned at 17:00 cannot go out at
17:30, and that is the operator's planning problem. Same warning as § 6, "modelling time too
finely".

### 7.5 The trip plan becomes rows when it becomes bookable — later, deliberately

Today a plan is one JSON column, `saved_plans.plan_json`. There is no itinerary item entity.
A normalised `trip_plans` table existed and was dropped on 2026-07-30, but as a **dead
duplicate** — `/kaia/plans` wrote to it while `/trip/{token}` always read `saved_plans` — not
because normalising was judged wrong.

**Decision: JSON stays for planning; rows arrive when a plan item becomes bookable.**
`Itinerary → ItineraryItem (date, time, is_booked, booking_id, …)`, converted from the
document and still updatable when the plan changes afterwards. Three things JSON cannot do,
each of which is already on the roadmap:

* **`booking_id` is a foreign key.** A JSON blob cannot hold one that follows a deletion.
* **"Which plans have unbooked items?"** is a query over rows, not over documents.
* **The collaborative direction** in `CLAUDE.md` — shared plans, comments, a log of who
  changed what — needs items for an author and a comment to hang off.

This is its own step and a larger one than it looks. It is not to be done in passing.

### 7.6 Shop products move from the site to the partner

`shop_products.site_id` scopes a catalogue to a website. **Decision: scope it to the
partner**, with a nullable `listing_id` for goods belonging to one property.

Why: one catalogue per business, usable on a listing's website, on a partner's website and —
the case that decides it — in the **business directory** noted in `WEBSITE_BUILDER.md` § 5a,
which wants to search across every partner's goods and today would have to join through
`sites`.

This does **not** contradict "the website owns its own data" (`WEBSITE_BUILDER.md` § 5a).
That rule protects *content* — text, images, layout. Goods are the business's own records,
nearer to a listing's menu than to a hero image.

**Precondition, settled: `sites.partner_id` becomes required.** It is nullable today, with a
comment saying a shop has no partner. That comment is stale — partner websites are generated
from the partner record, which has carried `logo`, `image`, `gallery`, `short_description`,
`address` and coordinates since 2026-08-14. If products hang off the partner, every site
needs one, or there are sites whose products have nowhere to live.

**`source_listing_id` is not redundant with it, and must not be replaced by a derivation.**
The obvious objection is that a listing implies a partner, so one column could be computed
from the other. It does not: **`listings.partner_id` is nullable**, and an unclaimed scraped
listing — the ordinary case, not the exception — has none. The two columns answer two
different questions and both are needed:

* `partner_id` — **whose site is this?** The paying customer. Required.
* `source_listing_id` — **where did its content come from at creation?** An optional import
  source, and § 5a of `WEBSITE_BUILDER.md` is explicit that it is not a dependency.

A second reason not to derive it: a listing can be **claimed later**, at which point a
derived owner would change under the site's feet. A stored column does not move.

**Nothing is needed for putting a shop on a page.** The block editor in the Content modal
already offers every block type rather than only the template's — `BlockForm::builderBlocks()`
iterates `BlockRegistry::all()` — so a shop block can be added to a restaurant's or a
craftsman's site today. Only *generation* limits `shop` to the `retail` template.

### 7.7 Order lines keep the frozen name **and** gain the reference

`inquiry_items` freezes name, quantity, unit price and line total — right, and it stays:
an order must not change when the business edits a price. It carries `menu_item_id` so a
line knows which dish was meant. **Decision: add a nullable `shop_product_id` alongside it**,
so a goods line knows its origin too. Needed for a basket, for re-ordering, and for "what
sells".

**A basket needs nothing else.** `SiteOrder` and `MenuOrder` already price N lines at once
and write them as `inquiry_items`; an order today is multi-line without a stored basket. The
basket is a session layer on top, not a change to this table. And a direct purchase is a
basket with one line — the same path, so there is never a second kind of order to maintain.

### 7.8 The restaurant menu stays separate from shop products

Asked directly: should restaurant orders just use `ShopProduct`? **No**, and the first reason
alone decides it:

1. **A shop product hangs off a site; a menu hangs off a listing.** The menu has to work on
   namibway.com, where Kaia recommends the restaurant and the traveller orders or reserves —
   and there is no site there. Merging would mean either every restaurant must first buy a
   website before its menu exists, or the menu cannot be shown on the travel platform.
2. **It would undo a decision already recorded as closed.** `WEBSITE_BUILDER.md` § 5a: the
   website owns its own data, the listing is an optional import source, "Decided. Not up for
   assessment." A menu is the listing's platform data; a shop product is the customer's
   website data.
3. **Different lifecycles.** `is_available` means "the kitchen is out today, back tomorrow" —
   a switch a waiter flips. `status` means published or draft. A dish has a section on a
   card; a product has its own page, slug, gallery and Instagram provenance.

On simplicity, which is the right yardstick: merging saves **one** small leaf table and buys
a nullable XOR (`listing_id` or `site_id`) plus a branch at every read. That is the more
expensive option, not the cheaper one. What the two genuinely share is the **order line**,
and that is already shared — `inquiry_items`.

### 7.9 Naming: what `Kind` means, and why `InquiryKind` keeps it

`Kind` is not industry vocabulary for reservations; it is a general software convention.
Both forms are already in use here, and the split turns out to be meaningful:

* **`*Type` classifies the entity** — `ListingType`, `BusinessType`, `ConnectorType`,
  `DiscountType`, `SettlementType`, `RouteTripType`.
* **`*Kind` discriminates variants of one record** — `DocumentKind` (file or wiki page),
  `InvoiceKind` (invoice or credit note), `GuestKind`, `ChargeKind`, `InquiryKind`. It
  decides which columns on that row mean anything.

**Decision: that split is now the rule rather than an accident**, and `InquiryKind` keeps its
name. Beyond consistency there is a second reason: `InquiryType` beside `ListingType` would
read as its sibling, and the whole point (see § 6) is that the shape of a request is *not*
the vertical of the listing. The different word carries that distinction.

**Where it sits against a standard.** The concept is not invented, only the labels are.
Schema.org separates `Reservation` — with `LodgingReservation`, `FoodEstablishmentReservation`,
`RentalCarReservation`, `EventReservation` — from `Order` with its `OrderItem`. That is line
for line our `booking` / `table_reservation` versus `order`, with `inquiry_items` as
`OrderItem`. `Inquiry` is kept as the umbrella because it says something the standards do not
at that point: it is a **request** that may be declined or expire, and only confirmation
turns it into a `Reservation`.

### 7.10 Order of work that follows from the above

1. `base_rate` rename, with the fallback rule written into the model. Mechanical.
2. `inquiry_items.shop_product_id`. Small, and unblocks the basket later.
3. Vehicle hand-over times, hand-over hours, turnaround buffer.
4. `/availability` — one endpoint, the table in § 7.1, slots in the response.
5. One picker component driven by that response, replacing `RoomTypePicker`.
6. Bind it to activity, restaurant and vehicle in the trip plan, not only `day.accommodation`.
7. Decide `sites.partner_id` required, then move `shop_products` to the partner.
8. `ItineraryItem` — its own step, when plan items become bookable.

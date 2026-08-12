# Project status and what's next

**Written 2026-08-10. Last brought up to date 2026-08-12.** This is a snapshot plus
direction, meant to brief a fresh session that will pick up one of the two new
workstreams. `CLAUDE.md` holds the standing rules and the architecture — read that
first; this file says where things actually stand and what has to be decided before
the next thing gets built.

Add a dated line when something here changes state. A status file that quietly goes
stale is worse than none, because the next session will trust it.

> **What changed since this file was written, in one paragraph.** Workstream A is no
> longer "substrate exists, product does not". All eight steps of `BOOKING_SYSTEM.md`
> §6 are built — rate plans, per-person pricing with guest categories, board basis,
> promotions, a per-partner live switch, amenities, the customer entity and the
> `Inquiry` → `Reservation` bridge, and taxes and fees. Two things this file recorded
> as unbuilt are built: the pricing model it said blocked everything, and the
> traveller-facing picker now reads the lodge's own calendar. On top of that sits the
> work of 2026-08-12: time inside a day (departures), and both of the open bugs
> `BOOKING_SYSTEM.md` had recorded. Sections 2 and 3 below carry the detail and the
> dated corrections.

---

## 1. Where the platform stands

Live in production, auto-deploying from green `main`, nightly encrypted backups.
The MVP foundation is done; the work is depth, not scaffolding.

Three business lines now exist, and only the first has software behind it:

| Line | State |
|---|---|
| **Travel platform** (namibway.com) | In production. Kaia interview → trip plan → booking requests. The flagship is the trip plan — see `TRAVEL_PLAN.md`. |
| **Websites for Namibian businesses** | **Since 2026-08-12 a generated site is real and viewable** — content model, block library, one template, the public renderer and `sites:generate`. No editor, no customer live yet. Workstream B below. |
| **Custom software / booking system** | Sold as a proposal to NWR. **Since 2026-08-12 the lodge-facing product exists** — a lodge can price, sell, block, check a guest in and read its morning board, and a tour operator can sell a seat on a departure. No partner is connected. Workstream A below. |

Marketing material for all three lives in `marketing/` and is downloadable from the
admin panel under **Documentation → Marketing material**. `marketing/README.md`
records what we may and may not claim — that list is load-bearing, because the
booking-system flyer is addressed to a named organisation.

**Added 2026-08-12 — Documents.** That page stays what it is: flyers built from source
in `marketing/` and committed, so they can be rebuilt. Everything else the team needs to
keep — signed documents, logos, drafts, notes written down so they stop living in chat —
now has a home at `/admin` → Content → **Documents**: a file explorer over a folder
tree holding uploaded files or pages written in the panel, each with a comment log.
Project-management tooling, not a partner-facing store; the files sit on the private
disk and are backed up nightly. See
`CLAUDE.md` → "Documents — the team's own filing cabinet" for the two constraints that
must survive changes there.

---

## 2. What the booking substrate actually is

**Read this before designing anything in workstream A.** These are verified from the
code, not from memory, and several of them are the reason the lodge system is a real
build rather than a UI on top of what exists.

> **Updated 2026-08-12.** Two of the bullets below said the traveller-facing flow and
> the lodge-facing calendar were separate. **They are no longer**, and that was a
> deliberate step with a guard test behind it. Corrected in place below rather than
> deleted, because the separation is what the rest of this file was written against and
> a reader needs to know it moved.

- **`Inquiry` is the booking record for the traveller-facing flow.** Its statuses are
  request-shaped, not stay-shaped: `pending`, `processing`, `on_request`, `nwr_pending`,
  `confirmed`, `cancelled`, `failed` (`App\Enums\InquiryStatus`). Stay-shaped states
  exist separately as `App\Enums\StayStatus` on `Reservation`. **Corrected 2026-08-12:**
  the bridge between them is built — `App\Services\Booking\StayPromoter` turns a
  confirmed inquiry into a real stay, once, idempotently, keyed by a unique
  `reservations.inquiry_id`. An `Inquiry` is still the *request* and a `Reservation` the
  *stay*; the promotion is one-way and they are not two names for one thing.
- ~~**Traveller-facing availability is still derived, never stored.**~~ **Corrected
  2026-08-12.** `App\Services\Booking\RoomAvailability` now returns the **smaller of two
  counts**: what the lodge's own ARI calendar has free, and what is left after the
  requests already asking for the same nights. So a stay taken at the desk stops being
  offered online. Prices follow the same route — `App\Services\Booking\RoomOffers` quotes
  the trip plan from the property's own rate plan, taxes included. Requests on a property
  whose inventory we hold now also take a provisional stay on the calendar, so the second
  count shrinks toward zero on its own as coverage grows.
- **One inquiry is still implicitly one unit.** The `inquiries` table has no quantity
  column. A `Reservation` can hold several room types with quantities; an `Inquiry`
  cannot. **Still true 2026-08-12** — and now the clearest remaining asymmetry between
  the two models.
- **`BookableUnit` (`RoomType` until 2026-08-12) still carries a single flat `rate_per_night`** plus `total_units`,
  `max_adults`, `max_children` and a string `code` — but those are *defaults*, and the
  calendar overrides them per night, which is where seasons live. **Since 2026-08-11** a
  rate plan sits between: what a night costs is (room type, date, plan), and what the
  plan's number is *per* — a unit, a person sharing, an occupancy — is the plan's
  strategy. **Since 2026-08-12** a unit may also carry a timetable (`booking_slots`), and
  then the counter and the rate are keyed to a departure rather than to the night.
- **`max_adults` / `max_children` are now enforced**, which they were not.
  `App\Services\Booking\RoomCapacity` owns the arithmetic; the website refuses a party
  that does not fit and the desk is warned and asked what it is doing about it. Fixed
  2026-08-12 — see `BOOKING_SYSTEM.md`, "capacity was a filter, not a rule".
- **Soft holds exist**: `inquiries.hold_expires_at` with `ExpireNativeHoldJob` releasing
  the hold and mailing the guest, idempotent and status-guarded. **Since 2026-08-12** the
  hold also takes a provisional stay on the ARI calendar, so the room genuinely comes off
  sale everywhere rather than only being subtracted by one reader.
- **Partner response is one click**: signed confirm/decline URLs (`routes/partner.php`)
  and the same transition from the partner panel, both through `InquiryDecisionService`.
- ~~**The partner panel is thin.**~~ **Corrected 2026-08-12.** It has seven resources
  (Inquiry, Listing, RatePlan, GuestCategory, Promotion, Charge, Customer) and four
  pages (occupancy calendar, arrivals board, rates and availability, getting started).
  A lodge can price, block, take a walk-in, move a guest through the day, look a customer
  up by name or phone, and read its own morning board.
- **Connectors exist and none are validated.** `ResConnect`, `NightsBridge`, `HopeCloud`,
  `Nwr`, `Native`, `Wetu`, plus manual. Not one has run against a real partner account.
  `NwrConnector` is deliberately a concierge stub: NWR has no API, so availability always
  returns "on request" and the team checks manually. **Still true 2026-08-12, and now the
  single largest unknown in this workstream** — everything else has been exercised at
  least by a test.

The consequence, as of 2026-08-10, was that the traveller-facing model could express *"a
traveller asked for a room and a partner said yes"* and nothing more. **As of 2026-08-12
that sentence is out of date in both halves**: the lodge-facing model expresses a
seasonal rate, a per-person tariff, a maintenance block, a booking of three rooms, a
walk-in, a guest standing at a desk, a seat on a tour, a levy and a customer's history —
and the traveller-facing flow now reads that model rather than a parallel one. What is
missing is not screens; it is a real partner on the other end.

---

## 3. Workstream A — a booking system lodges can operate

**Goal:** a booking system that lodge staff operate themselves, with NWR as the first
partner we try to connect. The flyer we hand them proposes a pilot on **one camp for one
season, running alongside what they use today** — that promise should shape the build.

### What is missing, concretely

Ordered so that each item depends only on the ones above it:

1. ✅ **A calendar table.** `bookable_unit_calendar_days` — per unit, per night: units,
   rate, minimum stay, closed-to-arrival, closed-to-departure. Sparse, with null meaning
   "follow the room type's default". Done 2026-08-11.
2. ✅ **Quantity per booking** — `reservations` + `reservation_units`: several room types
   with quantities under one guest. Done 2026-08-11.
3. ✅ **Blocking** — `inventory_blocks`, counted separately from sales so an occupancy
   view can tell "sold out" from "taken off sale". Done 2026-08-11.
4. ✅ **A stay lifecycle** — `App\Enums\StayStatus`: provisional, confirmed, due-in,
   in-house, checked-out, no-show, cancelled, cancelled-late, with the legal transitions
   enforced in `InventoryWriter`. Done 2026-08-11.
5. 🟡 **Front-desk surfaces**: reading and doing are both built — an occupancy
   calendar, an arrivals/departures board, manual booking entry, the stay lifecycle,
   block editing and a bulk rate editor (2026-08-11, below), plus day/week/month ranges,
   an hour axis for departures and passenger lists (2026-08-12, below). What is still
   missing is room-level assignment, for a lodge that assigns real rooms rather than
   room types. That is deliberately *not* modelled yet: a reservation holds room types
   and quantities, never a named room.
6. ✅ **Multi-property under one partner.** NWR is one partner with many camps. The
   partner panel now has a property switcher in its topbar, scoping the lodge-facing
   screens; the existing Listing and Inquiry resources are unchanged and still show
   everything the partner owns. Done 2026-08-11.
7. 🟡 **Money**: what a stay costs, what was paid, what is owed. What a stay *costs* is
   answered thoroughly as of 2026-08-12: a per-night breakdown (`reservation_nights`),
   rate plans with three pricing strategies, guest categories and age bands, promotions
   with a claimed cap, price overrides recorded as a discrepancy rather than a flag, and
   taxes, levies and fees frozen onto the stay. **What was paid and what is owed is still
   not** — there is no folio and no payment collection, and Stripe remains Phase 2. That
   gap is now the whole of the remaining "money" question rather than half of it.

### 2026-08-11 — inventory substrate (slice 1 of 3)

Domain layer only, no UI. Standard-shaped rather than bespoke: **ARI** (Availability,
Rates, Inventory), the model NightsBridge, ResRequest and every channel manager speak, so
a future connector is a mapping rather than a translation. The standards rule this came
from is now written down in `CLAUDE.md` → "Standards".

What landed:

- `bookable_unit_calendar_days` (created as `room_type_calendar_days`), `reservations`, `reservation_units`, `reservation_nights`,
  `inventory_blocks`, and `regions.country_code`. All additive; no existing table changed
  destructively and nothing was backfilled over existing data.
- `App\Services\Inventory\InventoryWriter` — **the single write path.** Enforced twice: a
  runtime guard on the models throws on any Eloquent write from elsewhere, and an
  architecture test fails CI if query-builder writes to those tables appear outside the
  namespace. This is the down payment on the deferred work (ledger, allotments, channel
  sync, offline replay), which all become changes inside that one class.
- `App\Services\Inventory\AvailabilityCalendar` — the lodge-facing reader, deliberately
  **separate from `RoomAvailability`**, with a test asserting they stay independent.
- Country resolution: `listings.city_id → cities.region_id → regions.country_code →
  config/region.php` (currency, timezone, tax, cancellation window), via
  `App\Support\CountrySettings`. No hardcoded Namibia in the booking domain.
- `InventoryDemoSeeder` (development only) — one lodge, three room types, two seasons,
  blocks and ~12 weeks of stays, all written through the writer.

Concurrency is settled in the database, not in PHP: availability is a counter moved by a
conditional `UPDATE`, so of two transactions racing for the last unit one changes a row
and the other changes none. The test forks real processes, and was **verified by
mutation** — replacing the conditional update with the naive check-then-write makes it
fail.

Deliberately not built: the `Inquiry` → `Reservation` bridge (designed and written up in
`CLAUDE.md`, no code), staged confirmations, ledger, allotments, channel sync, iCal,
offline operation, payments, folio, housekeeping, tax reporting.

### 2026-08-11 — the calendar and the arrivals board (slice 2 of 3)

The half a human touches, in the existing partner panel (`/partner`). **Read-only on
purpose**: no creating a booking, no editing a rate, no dragging a bar. Entering a
booking is slice 3, and keeping the two apart is what made this one shippable on its own.
Nothing added here calls `InventoryWriter`, and a test asserts it by watching for writes
while both screens render.

What landed:

- **Property switcher** (`App\Filament\Partner\Support\SelectedProperty`) in the panel
  topbar, remembering the choice in the session but re-resolving it against the partner's
  own listings on every read — an id put into a session by hand selects nothing rather
  than someone else's lodge. Only accommodation listings are offered, because the screens
  read room types. Filament's own multi-tenancy was deliberately *not* used: it would
  rewrite every panel route to `/partner/{tenant}/…` and force tenant scoping onto the
  existing Listing and Inquiry resources, which still show everything the partner owns.
- **Occupancy calendar** (`/partner/calendar`) — room types down, nights across, stays and
  blocks as bars, with units free and the rate per night in every cell, restrictions as
  quiet markers, and sold-out and overbooked as distinct states. A block is drawn with a
  hatch rather than a colour, so "off sale" survives a monochrome print and a colour-blind
  reader.
- **Arrivals and departures** (`/partner/arrivals`) — one date, three movements, printable.
  Deliberately three plain tables rather than Filament tables: this gets carried to a desk,
  and pagination and search controls do not survive a printer.

Two things are worth knowing before touching either screen:

**Data loading is separate from rendering, and that is load-bearing.**
`App\Services\Inventory\OccupancyGrid` returns plain DTOs — columns, rows, cells,
lane-packed bars already clipped to the visible range — so the Blade does no date
arithmetic and issues no queries. A month across twenty room types is a *fixed* number of
queries, and a test asserts the count is identical at three room types and at twenty,
because the failure mode here is one query per cell on a satellite link at a reception
desk. The bulk read is `AvailabilityCalendar::snapshot()`; the sparse-calendar rules (a
missing row or a null override means "follow the room type") moved to
`CalendarSnapshot` and the single-night reads now call the same three helpers, so the
grid and the booking path cannot drift apart.

**The panel has no custom Filament theme**, so the only Tailwind that exists in the built
CSS is whatever Filament's own views use. The dense grid is therefore plain CSS in
`resources/views/filament/partner/partials/lodge-styles.blade.php`, on Filament's own
palette variables with literal fallbacks. If a Filament theme is ever added, that file is
the thing to fold into it.

Deliberately not built here: entering or editing a booking, editing rates or restrictions,
room-level assignment, a phone layout (laptop first, tablet usable — that was the brief),
and any control for something that does not exist yet (no sync, no iCal, no payments, no
housekeeping, no invoicing — a disabled button is a claim).

### 2026-08-11 — entering bookings, a demo tenant, and the panel's own host (slice 3 of 3)

The half a lodge *does*, plus the two things that make the system showable and sellable
rather than only usable.

**Manual booking entry.** A walk-in or telephone booking, entered in the partner panel.
Clicking a free cell on the calendar opens the form with that room type and that night
already filled in; a night with nothing left is inert, because a form that could only
refuse is a dead end. One date range for the whole booking rather than one per room type
— the writer supports the latter, but a front desk booking two rooms is booking them for
the same nights, and two rows naming the same room type are merged so "2 standard" typed
twice means three rooms and not two bookings.

`App\Services\Inventory\ManualBooking` checks availability and restrictions *before* the
writer does, and that is its whole reason to exist: the writer's refusal arrives after the
save, and a guest is standing at the counter. It answers with the room type and the night
— "only one of two Standard Chalets is free on 14 September" — rather than "sold out". The
writer still refuses independently, because two people can be typing at once, and that
refusal is reported as a fact rather than an error.

**Editing what slice 2 displayed.** Stay lifecycle from the drawer, on both the calendar
and the arrivals board — the board is where a guest is actually checked in, and sending
somebody to the calendar to change a status would be sending them to the wrong screen. The
buttons are generated from `InventoryWriter::allowedTransitions()`, so a screen cannot
offer a move the domain would reject. Cancelling is deliberately not among them: it gives
rooms back, so it goes through `cancel()` and asks why. Blocks can be created, edited and
released; editing is release-then-consume in one transaction, so a widened block that no
longer fits rolls back to exactly the block that was there before.

**Bulk rates** (`/partner/rates`). A range of nights, optionally narrowed to certain
weekdays. **An empty field means "leave this alone"** — the same rule the Excel import runs
on — so setting a weekend surcharge cannot silently clear a minimum stay; clearing exists
but has to be chosen. Lowering capacity under what a night has already sold is refused
before the write, naming the room type and the night, rather than surfacing a Postgres
CHECK violation.

**`booking:demo-tenant`.** A sandbox partner built from a real listing, so a meeting opens
with the prospect's own lodge running rather than an empty grid.

> **Corrected 2026-08-11, after it failed on the first real attempt.** The command
> originally refused any listing with no room types — which is *every* listing in
> production, so it could not be built for a single lodge. It now invents a plausible
> three-tier room list when the lodge has none on file, anchored to the listing's own
> "from" price and its country's currency, and says out loud that it did so. A demo that
> needs a precondition nothing satisfies is not a demo.

It gets its **own
unpublished copy** of the listing: room types belong to a listing, so copied rooms would
otherwise hang off a published listing and appear on namibway.com, and every edit a
prospect made would be an edit to real content. Re-running wipes and rebuilds that tenant
through `InventoryWriter::purgeProperty()`, which refuses anything but a demo tenant.
`--all`, `--destroy` and `--list` do the obvious things. The command prints a signed
sign-in link plus an address and password, so a laptop can be handed across a table; the
controller refuses any account that is not a demo account, which is the check that matters
— a signature says who made a link, not which accounts may be opened with one.

> **Deviation from the brief, decided with the author.** The brief required a central
> outbound-suppression mechanism: a demo tenant was to emit nothing at all. The decision
> was that the demo should simply work, SMTP included, because a demo that cannot show a
> confirmation email is a worse demo. The failure mode the brief actually named — a demo
> booking mailing a real lodge owner — is closed by what the copy *contains* instead of by
> a layer somebody can forget to switch on: the tenant inherits no contact email, no
> phone, no website and no connector credentials, so there is no third party's address
> inside it at all. Every address it does hold is a plus-address on the team mailbox
> (`config('booking.team_address')` → `team+okonjima-bush-camp@namibway.com`), so a demo
> booking sends real mail through the normal mailer and still only ever reaches us, tagged
> by lodge. A test walks the tenant asserting exactly that.

**The panel on `booking.namibway.com`**, via `config('booking.panel_domain')`. Unset — local
development, CI, and production until the DNS record exists — nothing changes. Set, the
panel binds to that host, `booking.namibway.com/` redirects to `/partner`, and
`namibway.com/partner/...` forwards to the same path on the new host so bookmarks and
already-sent links keep working. **The signed confirm/decline links stay on the main site**:
a URL signature covers the host, so forwarding one would invalidate the thing that
authorises it — the exclusion is a pattern in `routes/partner.php`, not a matter of route
ordering, because ordering would be a trap for whoever adds the third signed route.

The server-side prerequisites are written up in DEPLOYMENT.md → "Buchungs-Subdomain": a DNS
record **at OVH** (namibway.com's DNS is not on Cloudflare — that is what broke
cdn.namibway.com), a certificate, an nginx server block, and `SESSION_DOMAIN=.namibway.com`
so a login is shared across both hosts.

Deliberately not built, still: room-level assignment, the `Inquiry` → `Reservation` bridge,
staged confirmations, ledger, allotments, channel sync, iCal, offline operation, payments,
folio, housekeeping, tax reporting. No control exists for any of them — a disabled button
is a claim.

### 2026-08-11 / 12 — the five slices after the three, in one paragraph each

`BOOKING_SYSTEM.md` §6 is the record; this is the index, so a fresh session knows what
exists without reading it.

**Rate plans** (step 1). Every listing got one default plan carrying its existing rates,
so nothing changed on screen. This was the migration that had to happen once and early:
retrofitting a dimension into the table the whole availability logic hangs on is the
excavation the single write path exists to prevent.

**Occupancy and guest categories** (step 2). Three pricing strategies, pure classes
checked against a table of examples; guest categories with age bands per property; the
booking form's occupancy rows. A property that prices per room sees none of it — no rate
switcher, no guest rows, no guest types — which is the rule the whole design is judged by.

**Board basis** (step 3), which is a rate plan, so the work was everything *around* it:
the board and the plan's name frozen onto `reservation_units` at booking, "sold as" on the
stay detail and the arrivals board, rooms-by-board for tonight (the kitchen's question),
and three setup profiles.

**Promotions** (step 4). A percentage, an amount, and free nights, because "stay 4, pay 3"
is what lodges here advertise. They never stack; a typed code beats a larger public offer;
a code that does not work refuses the booking rather than quietly charging full price. The
cap is claimed by a conditional `UPDATE` inside the booking transaction — two people typing
the last code at once is the same race as two people booking the last room.

**`partners.booking_enabled`, amenities, the customer, and taxes** (steps 5–8). The switch
and its per-partner demo mode; one shared amenity catalogue written from Namibian rate
sheets rather than a generic hotel list; `customers`, scoped to the partner and matched by
account first then email, because a booking system has a small number of main entities and
this one went six slices without it; and charges that apply to a finished price and are
frozen onto the stay.

### 2026-08-12 — time inside a day, and the two open bugs

**Departures.** A unit may now run a timetable (`booking_slots`), and a departure is
`(unit, date, slot)` with the slot null for everything sold by the night — which is every
row a lodge has ever written. The grid a day is *drawn* at is a property of the screen and
touches no table that counts anything; the counter stays one row per departure, moved by
the same conditional `UPDATE` as a night. Uniqueness is two partial indexes rather than one
over three columns, because SQL treats NULLs as distinct and a single index would have let
a lodge keep two counters for the same night.

**The screens for it.** The calendar became day / week / month with a month and year to
jump to, ranges that snap; the day view gained an hour axis with departures as columns, at
a resolution derived from the property's own timetable. The open question the design left —
one component transposed or two — was answered against a real grid, and it is **two**: a
night grid's second axis is a series of counters, an hour axis carries none and is a ruler.
Both readings go through the same `CalendarSnapshot` rules, which is what the decision
actually required.

**Selling one, and the morning board.** A collapsed "Departures" section on the room type
(a departure has no meaning apart from the thing it departs), a "+ Seat" on the day view
that opens the booking form knowing the unit, the date and the departure, and a passenger
list per departure on the arrivals board with the phone number — which is why the sheet
leaves the office.

**Both recorded bugs, closed.** Printing the arrivals board printed the menu: fixed with
one print stylesheet on the panel, which also turned up that this panel's page header is
*sticky* and therefore printed on top of the date. And capacity was a filter and not a
rule: the arithmetic moved into `App\Services\Booking\RoomCapacity`, the website now
refuses a party that does not fit, and the desk is warned and asked what it is doing about
it — recorded on the stay, because a receptionist told "no" by software they cannot argue
with writes the booking on paper.

**The guard test worth knowing about.** `AccommodationUnchangedByTimeTest` asserts that a
property selling nights does not notice departures exist. It started red: the bulk read the
whole occupancy grid is built on fetched both kinds of row and keyed them by date alone, so
whichever the database returned last silently became the day. That class of bug — a
*plausible* wrong number on a screen nobody looks at twice — is the reason these guards get
written.

### Parked on 2026-08-11 — and built on 2026-08-11 and 2026-08-12

> **Superseded, kept for the reasoning.** Everything in this section was written as
> *not being built yet*. All of it is now built, and the detail lives in
> `BOOKING_SYSTEM.md` §6 steps 1–8 rather than here. The analysis below is left in
> place because it is why the pricing model has the shape it has, and because a reader
> who finds only the answer cannot tell which constraints it was answering.

Two things were agreed and written down here so they would not be lost, and were **not**
being built yet, because the pricing question below changed what they sit on top of.

**A switch per partner, and a demo mode per partner. — Built 2026-08-11.**
`partners.booking_enabled` decides
whether a lodge is live on the booking system. While it is off, booking mail for that
partner goes to the team mailbox as a plus-address (`team+okonjima-bush-camp@namibway.com`)
instead of to the lodge — one resolver answering "where does post for this partner go?",
called by the five places that currently write `Mail::to($partner->email)`. Separately,
each partner gets a **demo mode with an address of their own**, so an operator can put
test bookings through their real inventory and receive the mail themselves before being
switched on. Both together replace the `booking:demo-tenant` construct, which exists today
only because there was no other way to show the system working. Built as described:
three states, and the only difference between them is who receives the mail.
`BookingMailbox` is the single place that decides. `booking:demo-tenant` still works and
is now the lesser tool — a partner evaluating the system against their own real
inventory needs no invented sandbox.

**What the pricing model cannot express, and has to. — Built 2026-08-11 and 2026-08-12.**
This was the larger one, and it blocked the above from being finalised:

- **Namibian lodges price per person, not per room.** "Per person per night sharing" plus
  a single supplement is the norm; per-unit pricing is the exception, for self-catering
  units, guest farms and campsites. Both models have to coexist — one property can sell
  chalets per person and campsites per unit — so the mode belongs on the room type.
- **`adults` and `children` are recorded on a reservation and change nothing.** The price
  comes from `rate_per_night` alone, and `max_adults` is not even enforced.
- **Children are priced in age bands** (commonly 0–2 free, 3–11 at about half, 12+ adult),
  and the boundaries differ per property, so they cannot be a constant. Pricing by band
  needs the children's *ages* at booking, not just a count.
- **Board basis** — B&B, DBB, full board — is part of what a per-person rate means, and at
  Namibian lodges DBB is closer to the rule than the exception.
- **NWR has resident / SADC / international tariffs.** For a state operator that is not a
  refinement, it is a requirement: a system with one price per night cannot represent the
  first partner we are aiming at.

The shape this points to leaves the ARI calendar as it is — one rate per room type per
night — and changes only what that rate is *per*: a mode on the room type, a single
supplement, child bands with percentages, and guest ages captured at booking. That is the
same shape channel managers use, so it stays a mapping rather than a translation.

**What was actually built, against that list.** The shape held, with one correction: the
mode belongs on a **rate plan** and not on the room type, because a property sells the
same chalet as B&B and as DBB, and to a resident and to an international guest, at
different numbers. So `rate_plans` sit between the calendar and the price, each carrying
a pricing strategy (`App\Services\Pricing` — per unit, per person sharing, per
occupancy), and the calendar is keyed by plan as well as by room type. Guest categories
carry the age bands per property rather than as constants; board basis is a property of a
plan and is frozen onto the stay at booking, because a plan renamed in March must not
change what February sold; and resident / SADC / international is three plans side by
side, which is one of the three setup profiles a property can start from. Every point on
the list above is answered except one — **guest *ages* are not captured at booking**, only
the category somebody chose, which is enough to price and not enough to audit. That is the
one item from this analysis still open.

### Constraints that are specific to this market

- **Connectivity.** These are camps in Etosha, Sossusvlei and Fish River Canyon. A
  system that assumes a live connection will fail at the desk. Decide early whether the
  front desk must work offline, because it is an architectural decision, not a feature.
- **NWR has no API.** Anything "connected" to them is either a person, a file exchange,
  or us becoming their system for that camp. The flyer proposes the third, scoped to one
  property.
- **Their current system stays running during the pilot.** Two systems holding
  inventory for the same rooms will drift; how that is reconciled is a design problem to
  answer before the pilot, not during it.

### Questions to answer before building

These change the shape of the system, so they are worth asking the user directly.
**Two of the four were answered on 2026-08-12** and are kept here with their answers,
because the answers are what the rest of the workstream now assumes.

- ~~Is this a **reservation system** or a **PMS**?~~ **Answered: far enough towards a PMS
  that a lodge can stop using its old one.** That follows from the NWR answer below rather
  than being a separate ambition — if a camp is to run on one system, we have to cover what
  their current system does at the desk, or they keep it for the invoice and the
  housekeeping list and we are back to two systems selling the same room. Concretely
  missing today: room-level assignment, an in-house/checked-out status on a stay, the
  folio and payments (`PAYMENTS.md`), housekeeping, and day-end reporting.
- ~~For the pilot, does our system take **all** bookings for that camp, or only ours?~~
  **Answered: the goal is one system — ours.** Until NWR agrees to that, two interim
  shapes, and neither pretends to be an integration: an **allotment** (they give us N
  rooms per date that we may sell without asking), or the manual concierge check that
  `NwrConnector` already implements. And explicitly: **other lodges first.** A property
  that actually hands us its inventory validates the system far better than the hardest
  partner in the market does, so NWR is the proof, not the starting point.
- Do lodge staff need it to work **offline**? Still open.
- Who owns the **rate calendar** — do they maintain rates in our system, or do rates
  arrive from theirs? Still open.

The allotment shape is worth one note, because it is much smaller than it sounds: an
allotment *is* a calendar row with `total_units = N`, which the ARI model already
expresses exactly. It needs no new architecture — only a marker that the row is somebody
else's inventory rather than ours, and a **release deadline**, since what is unsold a set
number of days before arrival falls back to the property. That deadline is the standard
term of every allotment agreement and is the reason a house grants one at all.

### What is actually next — rewritten 2026-08-12

The suggested first slice, and the seven after it, are done. The pilot the flyer proposes
— one camp, one season, alongside what they use today — is buildable with what exists.
What stands between here and that is no longer software of this kind:

1. **A real partner on the other end.** Every connector is written against documented
   behaviour and not one has run against an account. The first real integration will turn
   up surprises, and nothing else in this workstream can be de-risked without it.
2. **The reconciliation question, which is a design problem and not a feature.** Their
   current system keeps running during the pilot, so two systems hold inventory for the
   same rooms and will drift. **Answered 2026-08-12 in direction:** the goal is that NWR
   runs on one system, ours, and until they agree the two honest interim shapes are an
   allotment or the manual concierge check — with other lodges taken on first. What that
   leaves to build is the allotment marker and its release deadline; see "Questions to
   answer before building" above.
3. **Money owed** — designed 2026-08-12 in `PAYMENTS.md`, none of it built. Costing is
   thorough and the reservation carries the entire debit side; there is no credit side at
   all — no payment record, no invoice, no invoice number. Decided at the same time: we
   offer three settlement models rather than picking one (partner collects and we invoice
   commission; we collect everything and pay out net; deposit to us and the balance at the
   property — the last is the default, because a deposit set at the commission means no
   money has to move between us and the partner). Recording a payment is identical under
   all three; only who collects differs. Also decided: the settlement model is not a
   separate setting but is picked by the deposit share (0 % → agency, 100 % → merchant,
   between → split); commission is ours to set and the deposit is the partner's, both
   resolving listing → partner → platform setting → default; and NamibWay being a
   Namibian company rules Stripe out entirely, so slice 5 builds a demo provider that
   fully works and the real gateway is a later configuration step. `PAYMENTS_BUILD.md`
   turns all of it into six slices with acceptance criteria.
4. **Room-level assignment**, for a lodge that assigns real rooms rather than room types.
   Deliberately not modelled, and the first thing a real desk is likely to ask for.
5. **The API as the system's second front door** — decided 2026-08-12, written up as
   `BOOKING_SYSTEM.md` §8. The booking system has to be fully operable over
   `api.namibway.com` so an external seller (Expedia and its kind, a DMC mid-office, a
   partner site built on something else) can book a property that runs on us. Today's
   `/api/v1` is three read-only endpoints, and its availability endpoint proxies the
   *partner's* connector rather than reading our own calendar — so for exactly the
   properties whose inventory we hold, the public API is the least informed reader we
   have. Nothing about it is built.

Two smaller things are named rather than left implicit, both from
`BOOKING_BEYOND_ROOMS.md`: ~~renaming the sellable unit away from `room_type`~~ (§3.2 —
**done 2026-08-12**, it is `bookable_units` now), and deciding whether
the pricing strategies mean anything for a seat on a departure or whether the seat *is*
the unit (§3.4).

---

## 4. Workstream B — the website builder

**Goal:** build the websites we are selling, probably in this repo, on a block-based
builder rather than hand-building each site.

The flyer is the spec, and it constrains the build more than it looks: N$ 399/month all
inclusive, a draft in about a week, "we change it when you send a message", loads on an
old phone and a slow connection, and the customer keeps domain and content if they
leave. At that price the build has to be templated — a bespoke site per customer does
not survive the margin.

### Decisions that change the architecture

- **Same app or separate?** Recommendation: same repo, own tables, own routing entry —
  deploy, R2 media with on-demand thumbnails, i18n, PDF and backups are already solved
  here and would all have to be re-solved elsewhere. But keep tenant site content out of
  the travel domain models; the two should share infrastructure, not schema.
- **Tenancy and routing.** One site per customer, on a subdomain first and a custom
  domain later. Note the standing constraint from the media work: **namibway.com's DNS
  is at OVH, not Cloudflare**, which is what blocked `cdn.namibway.com` — custom domains
  and certificates need a real answer, not an assumption.
- **The block library is the product.** Hero, gallery, opening hours, map, WhatsApp
  button, contact form, price or menu list, about. A fixed, small set that covers the
  businesses on the flyer. Resist per-customer blocks; that is where the margin goes.
- **Who edits?** The flyer sells the agency model — the customer sends a message and we
  change it. So an admin-side editor is enough to start, and a customer-facing editor is
  a later, separate decision.
- **Rendering.** Server-rendered and light. The flyer promises an old phone on a slow
  connection, so the tenant sites should not ship the travel platform's JS bundle.
- **Overlap with `Listing`.** A lodge could be both a partner and a website customer,
  and its site could render from its listing. That is attractive and it is also a
  coupling — decide it deliberately rather than discovering it.
- **Billing and domains.** N$ 399/month recurring, plus registering and renewing
  `.com.na` domains on the customer's behalf. Stripe is Phase 2 on the platform and
  untouched; recurring billing in Namibia (cards, EFT, debit order) is an open question
  with an operational answer, not only a technical one.

### Questions to answer before building

- Subdomain only at first, or custom domains from day one?
- Does the customer ever edit their own site, or is it always us?
- Do we register domains for customers, and who pays the renewal if they leave?
- Is a shop or online payments in scope later? It is excluded on the flyer today.

### Suggested first slice

The block library and one template, an admin-side editor, subdomain hosting, and one
real customer's site live end to end. A second template only after the first customer
has been through the whole loop, including a change request.

### Built 2026-08-12 — slice 1: model, blocks, renderer, generation

A generated site is now a real, viewable page rather than a plan. `WEBSITE_BUILDER.md`
is superseded as a build brief by what is here; it stays useful as a checklist of what
this slice deliberately did not answer.

What exists:

- **Four tables** — `sites`, `site_pages`, `site_blocks`, `site_images`. The site owns
  its content; a listing is an import source at creation time and nothing else. A
  `site_pages.locale` column and a page row that never varies today are there so a
  German page and a second page are inserts rather than migrations on live customer
  content.
- **Twelve block types** (`App\Sites\Blocks` + `BlockRegistry`). Type is a string,
  payload is JSON, validation is per type on the model — so a new block is a class,
  never a migration. The layout each business type starts in lives in the registry.
- **One template**, server-rendered Blade with inlined CSS and about fifteen lines of
  JavaScript. No Inertia, no Vue, no Tailwind build, no external host of any kind.
  Site requests skip the whole `web` middleware group.
- **Host resolution before routing** (`ResolveSiteHost`, prepended globally). The
  platform's routes carry no host constraint, so this could not be a route file: a
  site group matching `/` would have replaced the travel home page. Costs one cached
  array lookup while no site has a host. `/_sites/{slug}` is the back door for review
  before DNS, and how CI exercises the renderer at all.
- **`sites:generate`** — from a listing or empty with `--name`/`--type`, re-runnable,
  and it reports what it left empty and why. Re-running refreshes what it wrote and
  never touches what has been edited since (the namibweb importer's rule).
- **A performance budget with a test behind it** (`config/sites.php`). A page carrying
  every block at once is 18 KB uncompressed against a 60 KB ceiling.

Decisions worth knowing before the next slice:

- **`publishable()` answers the wrong question for this product.** It means "may
  namibway.com show this", not "may a customer keep it". Google Places photographs pass
  it and are still not ours to hand over, so they are imported for prospecting, marked
  `prospect_only`, and `PublishGate` refuses publication while one is still referenced.
  Directory content is not imported at all. Unsplash placeholders are skipped loudly —
  a stock photograph on a paying customer's site implies it is theirs.
- **Provenance is coarser than it looks.** A listing has two source columns
  (`description_source`, and `photos_source` covering image and gallery together) and
  none at all for address, telephone, coordinates or opening hours. The mapping that
  follows from that is written out in `App\Sites\Generation\ListingImport`.
- **The booking block reads live and cannot yet book.** Availability and prices come
  from `RoomOffers` — a direct service call, not our own `/api/v1`, which is
  token-authenticated, has no booking endpoint, and answers availability out of the
  partner's connector rather than our calendar. The block quotes and then hands over to
  namibway.com.

### Added 2026-08-12 — the enquiry form, the legal foot, the burger

Three things the sites were sold as having and did not.

- **The enquiry form** (`App\Sites\Blocks\EnquiryBlock`, `SiteEnquiryController`). A
  marketing page with no way to write to the business is missing the only thing the
  visitor came for. It creates the same `Inquiry` the travel platform creates, on the
  listing the site was generated from — so the partner gets the mail with the signed
  confirm and decline links and the guest gets an answer either way, through a pipeline
  that already worked. An accommodation is asked for an arrival and a departure; an
  activity or a restaurant for a date and a **time**, and never a departure. There is no
  column for a time, so it rides in the free-text `travel_dates` beside the date.

  Neither the account requirement nor `ActiveRequestGate` applies here, deliberately.
  The gate stops one traveller putting the same speculative request to twenty lodges;
  somebody writing to the single business whose site they are reading is not that, and a
  registration wall would lose the enquiry. A rate limit and a honeypot are the
  proportionate guards, and a filled honeypot is answered exactly like a success.

  Two consequences of these pages having no session: `SubstituteBindings` is named on
  the route explicitly (the `sites` group is empty on purpose, and without it
  `{site:slug}` arrives as a string), and `withErrors()` has nowhere to flash to, so the
  outcome rides in the query string — with the referer rebuilt rather than concatenated,
  since a draft is read at `?preview=` and appending to that loses the token.

- **Privacy, the legal notice and the copyright line** (`App\Sites\LegalText`, columns
  on `sites`, rendered at `/privacy` and `/legal`). We write the first version, the
  business owns and edits all three from the Website tab, and confirms them when the
  site is published — which is also when they accept our website terms
  (`terms_accepted_at` / `terms_accepted_by`, with the terms URL in `config/sites.php`,
  empty until that page exists, because a Terms link that 404s in front of a prospect is
  worse than no link).

  This does not overturn the rule that the system writes no legal wording. What is
  generated is a *factual description of how this website works* — what the form
  collects, where it goes, that there is no tracking — which we know because we built
  it. Everything about the business itself comes off the record, so a blank address
  produces a shorter page rather than an invented one. The text is escaped and given its
  line breaks back, never rendered as markup: a paste out of a word processor must not
  be able to put a script on the customer's own site.

- **The mobile menu.** One array renders twice — the bar and the panel behind the burger
  — so a link can never be in one and missing from the other. Both the button and the
  panel arrive `hidden` and the page's own script unhides the button, so a browser with
  scripting off gets no control that cannot work. **Home** is first, and **booking is
  held aside from the five-item cap** rather than queued with the rest: it is the thing
  the site exists to do, so it must not be what falls off the end when a business has a
  lot to say. Highlights is in the menu now too.

- **The mark at the top** (`sites.logo_key`, set from either panel). One column holding a
  bucket key, not a row in `site_images`: a logo is a property of the site, not a picture
  a block points at, and it is the one image here that is never generated, never imported
  and never part of the content ladder. Absent is a finished state — the name is set in
  the site's display face instead. And over a hero the bar's name now **waits until the
  hero has scrolled past**, because it was being set twice in the same photograph.

- **The opening screen no longer repeats the name** (`App\Sites\HeroLines`). The bar
  carries the business's name and the hero used to carry it again four lines below, which
  on a draft shown to a prospect reads as a fault rather than as emphasis. The hero gets a
  short line true of the category instead — picked by a hash of the slug, so it is stable
  across rebuilds but not the same for every lodge in the country. A slogan is the
  business's to write and we do not invent one; every line is meant to be replaced, and
  `EditHeroAction` is where the headline, the paragraph under it and whether the town
  shows at all are changed, from either panel.

- **The bar has a fixed height, and that is load-bearing.** The hero is pulled up under it
  by exactly -64px, so anything that made the bar taller left a strip of page background
  above the photograph — measured at 834px the bar was 90px and the strip 26px, from the
  name wrapping to two lines and the menu wrapping beside it. `height` rather than
  `min-height`, `nowrap` on the name and the links, two size steps for a long name, and
  the burger threshold moved to 1024px. Do not reintroduce anything that lets the bar grow
  without changing the hero's margin in the same breath.

- **An open menu takes the bar off its photograph colours** (`.nav.is-open`, set by the
  page's own script), with the transition suppressed for that state — a 300ms fade left
  the name white on cream for exactly as long as it takes to notice.

The owner edits their own opening screen, legal text and logo from the partner panel,
through the same actions the admin uses (`EditHeroAction`, `EditLegalTextAction`,
`EditSiteLogoAction`) — the moment the two copies diverge, "the customer can also edit it
themselves" turns into two products with one price.

Still to do here: the confirm-by-email path — the owner accepting from the mail rather
than us ticking the box for them — and our own website-terms page, which
`config('sites.terms_url')` is waiting for.

### Next up, in the order it was asked for

- **The custom domain, entered in the admin, and nginx following by itself.**
  Decided 2026-08-12, deliberately deferred. A site's own domain is one field away
  today (`sites.host`), but a wildcard certificate does not cover somebody's own
  `.com.na` — each one needs its own certificate and its own `server_name`.

  The shape when it gets built: an admin field for the domain, and a **reconciler
  outside the application** — a root-side script on a systemd timer that asks the app
  which hosts are still without a certificate, checks each one actually points at us,
  issues it, writes the vhost, reloads nginx. HTTP-01 is enough there, since it is the
  customer's own domain resolving to our server; no OVH API needed, unlike the wildcard.

  **PHP must not be the thing that runs certbot.** A queue worker allowed to `sudo` and
  to write into `/etc/nginx` is a web process with root, and the 2026-08-11 outage in
  `CLAUDE.md` is a fair illustration of what one bad nginx file costs on this box. The
  app's half of this is read-only: a command that lists the pending hosts.

  For the customer it is still click-free, just not instant — DNS has to propagate, and
  the site runs on its subdomain in the meantime.

- **The subscription gates the owner's button.** The create-website action exists in the
  partner panel and is switched off (`CreateWebsiteAction::locked()`), because nothing
  yet knows whether a customer is paying. When the state machine lands, the entitlement
  check goes *behind* the action as well as on it — a greyed-out button is what an owner
  should see, and not what the platform should rely on.

### Decided 2026-08-12 — the answers slice 2 builds against

Every question this section listed as open has now been answered. Recorded here in the
form they were given, with the consequence each one has for the build.

- **Booking stays on the customer's site.** The guest never leaves it. Pressing "book"
  opens a NamibWay login/register modal — the shape of a Google sign-in — and once it
  closes the guest is connected and the booking completes in place.

  **The hard part is not the modal, it is the cookie.** A customer's site runs on its
  own host, and once we register their own domain it is a different registrable domain
  entirely — `bakkie-repairs.com.na` cannot read a namibway.com session cookie, and no
  `SESSION_DOMAIN` setting changes that. So this is not "add a login form to the tenant
  site": it needs the shape OAuth uses — a popup or redirect to namibway.com, which
  hands back a short-lived token the tenant site exchanges for its own session. Design
  that before building the modal. It also means tenant hosts stop being entirely
  session-free, which is a real cost against the byte budget and should be paid only on
  the pages that need it.

  This also settles the `ActiveRequestGate` question by implication: a guest booking the
  one property whose site they are on is not the flooding case that gate exists to stop,
  so that booking does not run through it. Write down where the boundary is before the
  first one is taken.

- **Both edit.** The customer can edit their own site, and we offer to do it for them as
  part of the monthly fee. Same fields, same tables, different surface and different
  permissions — there must never be an "admin version" and a "customer version" of a
  piece of content. Build the admin editor first: it covers the flyer's promise on its
  own, and it is what makes a prospecting draft fillable for the businesses that have no
  listing to generate from.

- **The subscription has to start by itself.** Stripe is known and unavailable in this
  market, so a payment provider still has to be found. **Until then: manual invoicing**,
  which means the subscription state machine gets built now and the collection is
  plugged in behind it later. Do not let the price or the provider leak into the state
  logic.

- **We register the domains.** A domain is cheap and it removes a step from a sale.

- **Liability: the customer always answers for the content, we answer for hosting and
  the technology.** That is the line the contract has to draw, and the technical side
  already supports it — the owner cannot introduce code, so what they publish is
  entirely what they typed into structured fields.

- **Multilingual throughout: EN, DE, NL, FR, ES** — the platform's five (`config/locales.php`),
  and what the flyer sells.

  **Correcting slice 1 honestly:** what exists today is a `site_pages.locale` column, so
  a second language is an insert rather than a migration. That is the foundation and not
  the feature. There is no language switcher, no per-locale routing, no way to say which
  languages a site publishes, and the renderer reads `default_locale` and nothing else.
  Five languages per site is real work, and it interacts with the editor decision above:
  whoever edits, edits one language at a time and has to see what is missing in the
  others.

---

## 5. Constraints that carry into both workstreams

These are already paid for in scars; `CLAUDE.md` has the detail.

- **Cost guards stay.** `EnrichmentBudgetGuard` exists because a redundant-lookup bug
  burned ~$840 in a day. Nightly enrichment is still off.
- **Never key a delete on a value derived from mutable config** — that nearly deleted the
  live photo library when a bucket URL changed.
- **Any bulk backfill over existing non-null data is destructive** until proven otherwise.
  Gate on "currently empty" or dry-run.
- **When a deploy breaks production, fix `deploy.sh`**, not just the server.
- **Don't hardcode Namibia.** The concept expands to other African countries; keep
  country-specific data in config or the database.
- **The one-active-request rule assumes exactly one responsible person per pipeline.**
  Anything that gives more people booking power has to reckon with that gate first.
- **The dangerous bug in this domain is the plausible one.** Added 2026-08-12, after a
  bulk read keyed two different kinds of calendar row by date alone and let whichever the
  database returned last become the day. Nobody looks twice at a calendar that reads eight
  free. Where two things share a table, a date and a key, file them apart rather than
  merely reading them apart — and write the guard test before the reader that needs it.

---

## 6. Consolidated open questions for the user

Nothing below is blocking today's work, but each one changes what gets built:

1. Booking system: reservation system or full PMS?
2. Booking system: must the front desk work offline?
3. Booking system: during the pilot, do we take all bookings for that camp or only ours?
4. Booking system: who maintains the rate calendar?
5. ~~Websites: subdomains only at first, or custom domains immediately?~~ **Answered
   2026-08-12** — subdomain first, and we register the customer's own domain. One `host`
   column serves both, so the move is an `UPDATE`.
6. ~~Websites: does the customer ever edit, or is it always us?~~ **Answered 2026-08-12**
   — both. Admin editor first, customer editor after; same fields, never two versions of
   a piece of content.
7. **Websites: how is N$ 399/month actually collected in Namibia?** Still open, and now
   the only one on this list that is. Stripe is out for this market, so a provider has to
   be found; manual invoicing bridges the gap and the subscription state machine gets
   built against it regardless. See §4.
8. ~~Both: is the website builder allowed to read from `Listing`, or are the two kept
   apart?~~ **Answered by the build** — a listing seeds a site once, by copying, and is
   never read at render time.

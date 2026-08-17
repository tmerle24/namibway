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
> work of 2026-08-12: time inside a day (departures), both of the open bugs
> `BOOKING_SYSTEM.md` had recorded, and **the whole money side** — all six slices of
> `PAYMENTS_BUILD.md` plus a DPO Pay provider, a payments guide for staff in `/admin`,
> and the three bugs a phone-width screenshot found afterwards. Sections 2 and 3 below
> carry the detail and the dated corrections.

---

## 1. Where the platform stands

Live in production, auto-deploying from green `main`, nightly encrypted backups.
The MVP foundation is done; the work is depth, not scaffolding.

Three business lines now exist, and only the first has software behind it:

| Line | State |
|---|---|
| **Travel platform** (namibway.com) | In production. Kaia interview → trip plan → booking requests. The flagship is the trip plan — see `TRAVEL_PLAN.md`. |
| **Websites for Namibian businesses** | **Since 2026-08-12 a generated site is real, viewable and sellable** — content model, block library, one template, the public renderer, `sites:generate`, an enquiry form that reaches the business, legal pages, and a customer's own domain issued without anybody touching nginx. What is still missing is the **content editor**: the frame is editable from either panel, the blocks are not. No customer live yet. Workstream B below. |
| **Custom software / booking system** | Sold as a proposal to NWR. **Since 2026-08-12 the lodge-facing product exists** — a lodge can price, sell, block, check a guest in, read its morning board, take money against a stay and issue a numbered invoice, and a tour operator can sell a seat on a departure. No partner is connected and no merchant account exists, so the online payment flow runs on the demo provider. Workstream A below. |

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
- **There is a credit side now. Added 2026-08-12.** The bullets above were written when a
  reservation carried only what a stay *owed*. It now also carries what has been paid
  (`paid_amount`, `payment_status`, both stored as results), and `payments`, `invoices` and
  `payment_intents` exist beside it. Two consequences for anything designed against the
  older list: **money is written through `PaymentRecorder` only**, the same discipline as
  `InventoryWriter`, and **who collects the money is derived from the deposit share**
  rather than stored, so there is no settlement-model column to read.
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

### 2026-08-12 — the ledger (money, slice 1 of 6)

**The credit side exists.** `PAYMENTS_BUILD.md` slice 1 is built: `payments` holds one row
per money movement against a stay, and `reservations` carries `paid_amount` and
`payment_status` as stored results. The complaint that started `PAYMENTS.md` — the system
could not say whether a stay had been paid — is answered, and answered without a payment
provider, because a lodge desk takes cash today.

**One write path, enforced the way inventory's is.** `App\Services\Payments\PaymentRecorder`
is the only thing that writes money; `PaymentWriteGuard` catches Eloquent saves at runtime
and `PaymentWritePathTest` refuses query-builder writes from anywhere else by scanning the
source. Deliberately a copy of the inventory pattern rather than a second shape for the same
rule. The one place the two paths meet is the folio total: the reservations table stays
written by `InventoryWriter` alone, so the recorder decides the numbers and calls
`InventoryWriter::recordFolio()` to write them — the same arrangement `StayPromoter` has.

**Nothing is ever edited.** A refund is a negative row on the same folio, a mistake is a
*reversal* that points at the row it corrects and leaves both, and the only field that
changes after creation is the status — `recorded → cleared` is a fact arriving late rather
than a fact being rewritten. Refund and reversal are kept distinguishable on purpose: "did
we give that money back, or did we never have it?" has two different answers to an
accountant.

**Two decisions worth recording.** A `recorded` payment counts towards the balance, and only
a `failed` one stops counting — a desk handed cash is not waiting for a bank, and a stay that
reads unpaid because nobody ticked a second box is a stay somebody gets asked to pay twice.
And a stay nobody has priced yet has *no* balance rather than a zero one: `FolioStatus`
answers it as part-paid the moment money arrives, which puts it on the unpaid list where
somebody prices it.

**Every comparison is in cents** (`Money::cents()`), never on the floats the decimal columns
cast to. The one-cent-short test is the guardrail's own test.

**On the screens.** The stay drawer both lodge screens open gained a Money block — paid, what
of that is confirmed, the balance, the lines, and buttons to record a payment, record a
refund, correct a line or mark a transfer as arrived. The arrivals board gained an
Outstanding column, and there is a new **Unpaid** page listing everything at the property
that is not square, soonest arrival first. A cancelled stay keeps its folio and appears
there — a forfeited deposit is exactly the case a system without a folio gets wrong.

### 2026-08-12 — the invoice (money, slice 2 of 6)

**There is now a legal document, not a PDF export.** `invoices` holds it: a number,
an issuer, a kind, and a **frozen JSON snapshot of the lines as issued**. Nothing reads
back through to `reservation_nights` or `reservation_charges` afterwards, which is what
lets a property rename a rate plan or change its VAT rate without rewriting last
season's paperwork. The same "stored as a result" rule the price already follows,
applied one level up.

**Numbering is gapless per series and year, and that is a locked counter row.**
`invoice_sequences` is read with `SELECT … FOR UPDATE` inside the issuing transaction.
Both obvious alternatives are wrong and wrong invisibly: `max(number) + 1` hands two
simultaneous check-outs the same number, and an auto-increment does not give a number
back when a transaction rolls back — and a hole in the run is the first thing an auditor
asks about, because the innocent and the fraudulent explanations look identical.
`InvoiceNumberingConcurrencyTest` forks six real processes to prove it, and was verified
by mutation: removing `lockForUpdate()` makes it fail on the unique index.

**An issued invoice cannot be changed.** The model refuses updates and deletes outright
— tested from *inside* the write path, because the write guard only stops code that
never meant to write money and this has to stop the writer too. A mistake is a **credit
note**: its own number, pointing at the invoice it corrects, every line negated so the
VAT reversal is visible rather than a lump sum, and the pair nets to the corrected
amount.

**One decision that departs from the brief, and why.** `PAYMENTS_BUILD.md` named a PDF
path column; there is none. The snapshot *is* the document and the PDF is a derivative
rendered on demand, exactly as thumbnails are derivatives of an original. Two reasons
this codebase has already learned: an invoice names a guest and what they paid, and our
media bucket is public with no per-object visibility — the trap the Documents store was
built to avoid; and a stored file is a second copy that can drift from an immutable row.
`/invoices/{invoice}/pdf` is therefore the only way to the document, and its check is the
access rule. Invoice numbers are sequential by design, so that check is load-bearing:
without it one property could read every other property's takings by counting upwards.

**On the screens.** Issue an invoice from the stay drawer, see what has been issued
against the stay, open the PDF, and credit one that was wrong. The button says "credit
it" and not "edit", because there is no edit.

**Renamed while here.** `PaymentWriteGuard` → `MoneyWriteGuard` (with its trait and
exception), because it now guards invoices too and a third copy of the same class for the
next money table is how one rule quietly becomes three.

### 2026-08-12 — the two rates and where they are set (money, slice 3 of 6)

**Commission is ours, the deposit is the partner's**, and both resolve the same way:
`listing override → partner override → platform setting → config default`, with **null
meaning inherit**. Deliberately the rule the availability calendar already uses for its
sparse overrides — nothing has to be written to say "unchanged", and changing the
platform rate moves everybody who has not negotiated separately.

**The platform rates live in a settings row, not in `config/`.** `/admin` → Settings →
**Commission and deposits**, following `MessageSettings` + `MessagingSettings`. These are
commercial terms rather than configuration: they change when a conversation with a
partner changes, and needing a deploy for that means they get changed in a hurry by
whoever can deploy, or not at all. `config/payments.php` stays as what the row is seeded
from and the fallback before it exists.

**The deposit floor follows the commission** unless it is set explicitly — stored as a
null meaning "the commission rate" rather than as a copied number, so it keeps following.
That floor is where model C nets to exactly zero between us and the partner, which is the
cheapest arrangement that exists for both sides and therefore worth making the natural
landing spot rather than a coincidence.

**Both rates and their amounts are frozen onto the reservation** when it is taken —
`commission_rate`, `commission_base`, `commission_amount`, `deposit_rate`,
`deposit_amount`. A rate without its amount is half an answer ("5% of what?" is the first
thing a partner asks), and the base is a number nobody can reconstruct once the charges
have moved. Changing a platform rate next season must not rewrite what we earned last
season; a test asserts exactly that, and asserts that the *next* booking does get the new
rate — the freeze is about the past, not a refusal to ever change.

**The commission base is the stay before tax and levy.** An added VAT was never in the
stay amount; an *included* one is, and is taken back out. A property's own conservancy fee
is a `ChargeKind::Fee` and stays in the base, because it is revenue rather than somebody
else's money passing through. Charging commission on the government's VAT and the NTB's
levy is indefensible in front of an operator, and `ChargeKind` is what makes that
expressible rather than a guess.

**The partner panel edits the deposit and only the deposit.** The commission appears
there as a statement of what they pay, never as an input — and the guarantee is not that
the field is hidden but that `commission_rate` is not in that form's schema at all, so
posting it writes nothing. That is the test, rather than "the input is not rendered".

### 2026-08-12 — the three settlement models (money, slice 4 of 6)

**One number, three behaviours, and no way to contradict yourself.**
`App\Enums\SettlementModel` is *derived* from the effective deposit share — 0 % → agency,
100 % → merchant, anything between → split — and there is deliberately **no
`settlement_model` column anywhere**. A test asserts that across `partners`, `listings`,
`reservations` and `payment_settings`, because storing the model as well as the deposit is
what would make "merchant model, we collect nothing" configurable.

**A strategy class per model, answering exactly three questions** (`SettlementStrategy`):
what we ask the guest for now, what is owed between us and the partner afterwards, and who
issues the guest's invoice. The narrowness is the design — the folio, the payments, the
invoice and the commission calculation are identical in all three, so anything wider would
be a place for three models to drift into three answers about what we earn.

**A stay resolves its model from its own frozen deposit rate**, not from the property's
current one, so a statement about last season still adds up after a renegotiation.

**The balance between us and a partner is one signed number**, not an amount plus a
direction — because the interesting case is *zero*, and "zero, owed by nobody" would need a
third direction. At a deposit equal to the commission, `SettlementBalance::isSettled()` is
true and nothing has to move: no payout run, no statement, no reconciliation. That case has
its own test, because it is the one the default exists for.

**Commission is earned once and reversed on cancellation.** `commission_earned_at` is a
timestamp rather than a flag, so "what did we earn in March" is stable. A plain
cancellation clears it and *keeps the amount*, so the record of what the booking would have
earned survives; a late cancellation and a no-show keep it earned, because the guest was
charged and the room was held — earning nothing there would be us absorbing the property's
penalty.

> ⚠️ **Still a § D question.** *Precisely* when commission is earned — at confirmation, at
> the cancellation deadline, or after check-in — and what a no-show earns are commercial
> decisions that have not been made. What is implemented is the reading of `PAYMENTS.md`
> § 3 that needs no scheduler: **earned at confirmation**. The rule lives alone in
> `App\Services\Payments\CommissionPolicy` precisely so the answer changes one file. The
> "cancellation window has closed" reading needs a nightly sweep and is worth building once
> the business has answered.

**0 % is a permission, not a number.** `partners.allow_zero_deposit`, admin-only. Without
it the deposit is refused with a sentence explaining that collecting nothing means we
invoice for commission instead; with it, zero is allowed and skips the floor, because that
arrangement has deliberately gone below it. Both panels state which model the current
deposit means, live, next to the field — the consequence belongs where the choice is made.

### 2026-08-12 — the provider abstraction, a working demo, and Paystack (money, slice 5 of 6)

**The whole flow works with no merchant account.** `DemoProvider` implements the
`PaymentProvider` interface entirely in-process — authorise, capture, decline, refund and
the asynchronous callback — with a hosted checkout page inside the app carrying **pay**,
**decline** and **abandon** buttons. Two things it does deliberately awkwardly, because a
demo that always succeeds instantly teaches the wrong shape: it fires its callback
*before* redirecting the guest back, and it can deliver the same callback twice.

**`payment_intents` is money we asked for; `payments` is money that moved.** That line is
the answer to what a declined payment produces — **no ledger row**. A guest who mistypes a
card three times leaves three attempts and no folio noise. `PaymentStatus::Failed` still
exists for the different case: an EFT somebody recorded as received that the bank did not
honour. Money believed to have moved and money that never started moving are different
facts.

**Idempotency is a unique index, not a check.** `payments.payment_intent_id` is unique, so
a repeated webhook, or a guest returning while one is in flight, cannot credit twice — the
same discipline `reservations.inquiry_id` uses.

**Nothing above the interface names a gateway**, and there is a test that greps the whole
of `app/` to prove it, excluding only `Services/Payments/Providers` and `config/payments.php`.

**Paystack is implemented, and the caveat is written into the class.** It does **not**
support Namibia as a merchant country (Nigeria, Ghana, Kenya, South Africa, Côte d'Ivoire;
Egypt and Rwanda newer), so a Namibian entity cannot hold an account — the same wall Stripe
presented. The workable route is a **South African entity settling in ZAR**, which is why
the currency handling is load-bearing rather than decoration: a NAD folio is charged in ZAR
at the Common Monetary Area's 1:1 peg, and the intent stores what was owed, what was
charged and the rate, so a refund returns the money that was taken. Whether to have such an
entity is a company decision and has not been made.

Security worth naming: the webhook signature is verified against the **raw** body (HMAC
SHA-512 — re-encoding JSON changes bytes, which is the classic way a check silently never
matches), an unverified or uninteresting event is a **200** so the gateway keeps
delivering, and the amount is never read from a callback — `transaction/verify` is asked
directly. A test asserts that a verify response quoting a different amount does not change
what is recorded.

**The demo tenant now shows money.** `booking:demo-tenant` settles about three quarters of
its invented stays — past ones paid in full at the desk, future ones with a deposit — so a
prospect opens a folio with something in it and an unpaid list with rows. Through
`PaymentRecorder` like everything else; the demo gets no shortcut, because a shortcut here
would be a second write path.

Traveller-facing copy for the payment pages lives in `resources/js/lang/*.json` with the
rest, read server-side by `App\Support\UiTranslations` — the pages are standalone Blade
rather than Inertia, because a guest arrives at them from an email or a gateway redirect
and booting the whole traveller app to say "your deposit is paid" would be slower and tie a
page a *payment provider* redirects to to the front-end build.

### 2026-08-12 — booking system or PMS, chosen at setup (money, slice 6 of 6)

`Partner.operating_mode` — `booking_only` or `full` — set when the account is created and
changeable by us in `/admin`. A booking-only property keeps rates, availability, bookings,
the calendar, the folio and the unpaid list; what it does not get is the **desk**: the
arrivals board and the check-in / check-out buttons, which are the only two front-desk
features that exist today.

**The rule that keeps this from being a second product**: the mode decides what a partner
*sees*, never how anything is recorded. Three tests hold it — a booking-only partner sees
no desk navigation and cannot move a stay even by calling the action directly; a full one
can; and the load-bearing one, that a **booking-only partner still has a folio, payment
records and an invoice**. Plus a source scan asserting that nothing under `app/Services`
reads `operating_mode` at all. The moment a service does, upgrading a partner becomes a
migration and two partners on one server stop being comparable — the "two systems" problem
this workstream exists to avoid, reintroduced from the inside.

Defaulting to `full` is deliberate: every partner that exists today already has the
arrivals board, and a migration that silently took a working screen away would be a product
change delivered as a schema change.

**With this, `PAYMENTS_BUILD.md` is worked through.** Step 6 of `PAYMENTS.md` §6 — payouts
and partner statements — is the only piece left, and it is the one that needs real money to
have moved before it can be tested. `SettlementBalance` already says what is owed on one
stay and in which direction; what is missing is the run that aggregates it, the statement a
partner reads, and the record of a transfer having happened.

### 2026-08-12 — DPO Pay, because it is the only candidate that covers Namibia

The provider question from slice 5 is answered as far as code can answer it. Every name in
`PAYMENTS.md` §5 was checked, and one of them stands up:

- **DPO Pay by Network** operates in Namibia with a team in Windhoek and **bills and
  settles in NAD**, with one documented public API (`createToken` / `verifyToken` /
  `refundToken` plus a hosted page). **Implemented** as `DpoProvider`.
- **PayGate** has been absorbed into the same group — not a separate choice.
- **Peach Payments** is live in South Africa, Kenya and Mauritius; Namibia is an announced
  intention, not an account anybody can open. Worth watching for a second reason:
  **ResRequest already integrates it**, and ResRequest is the dominant PMS in this market.
- **Ozow** and **Netcash** are South African bank-to-bank rails — Instant EFT works only
  with South African bank accounts, and Netcash's *is* Ozow.
- **PayToday** is genuinely Namibian and run by Nedbank Namibia, but it is a wallet with a
  plugin rather than a documented API. A method to add later, not a card acquirer.
- **FNB / Bank Windhoek / Standard Bank Namibia** do e-commerce acquiring through a
  relationship, usually behind a gateway. A banking conversation, not an integration.
- **Paystack** stays implemented but is not the recommendation — no Namibian merchant
  account, so it needs a South African entity settling in ZAR.

**Two things that need DPO on the phone, not a commit**, both written where somebody will
find them. The `verifyToken` result-code table: `000` is documented as "Transaction Paid"
and is the only code treated as final — **everything else is reported as still pending, on
purpose**, because a code guessed to mean "declined" writes off a payment that may have
arrived and the guest is the one who finds out. The genuine failures go into
`payments.providers.dpo.failure_codes` once known. And `ServiceType`, which is configured
per DPO account.

**One design change came out of it, and it is an improvement.** DPO's notification is not
signed, so it cannot settle anything by itself — it only says an attempt is worth asking
about. `PaymentGateway::settle()` therefore **always** calls `capture()` whatever a callback
said, which replaces a confusing three-way ternary and is the right shape for a signed
webhook too. A gateway body can now never set an amount, which is the difference between a
payment and a forged one. An attempt that stays open records what the gateway said, so a
pending payment is a state somebody can read rather than a dead end.

NAD needs no conversion through DPO, so the currency-peg machinery is untouched by it — it
exists for the Paystack-shaped case and is tested there.

### 2026-08-12 — the calendar opens the way it was left, and a desk mode

Two small things, both asked for by the same observation: the calendar is not a page
somebody visits, it is a screen somebody works at all day.

**It remembers how it is read.** The range, the day-view resolution and the rate plan being
shown are stored on the account — `users.panel_preferences`, read and written through
`CalendarPreferences` — so a lodge that works the week view opens on the week view. Three
rules keep it honest: the **date is never remembered** — a calendar opens on today, the way
a diary does; a **link never rewrites a preference**, so the query string still wins for the
page it addresses but a colleague's link leaves your own calendar alone; and the **rate plan
is per property**, since plans belong to one. The same store took over the property switcher
from the session, which used to forget which of NWR's twenty camps somebody works at every
time the session expired.

**Desk mode** is a button that fills the screen with the calendar — the reception screen,
where the sidebar, topbar and breadcrumbs are furniture. It asks for native fullscreen and
does not depend on getting it: the CSS state alone is a complete desk mode, which is what a
reception iPad gets, since iOS Safari has no element fullscreen at all.

### 2026-08-12 — a payments guide, and the three bugs a screenshot found

Two pieces of finishing work on the money side, both worth recording because of what
they say about how this gets checked.

**The guide.** `/admin` → Documentation → **Payments Guide** — one page written for the
person operating the panel and for the person explaining it to a lodge owner, at the
same time, because they ask the same questions in a different order and two pages would
drift. It carries how money moves, the three models, who owns which rate, a numbered
setup procedure that says who does each step and where, the gateway configuration that
lives in no panel at all (`PAYMENTS_PROVIDER`, `DPO_COMPANY_TOKEN` and friends), the
day-to-day actions at a desk, and the claims we may not make. `PaymentsGuideTest`
asserts the *sentences*, not the layout — a page that renders and has quietly lost the
line about VAT is worse than one that fails, because somebody reads what is left and
answers a partner wrongly.

**Then the panel was opened at 375 px, which is a step `PAYMENTS_BUILD.md` § E asks for
and which found three real bugs that the whole test suite did not:**

1. **Wide tables were cut off, silently.** Nothing overflowed, nothing scrolled sideways,
   no element was missing — an ancestor clipped the right-hand columns. The stay drawer
   lost the payment State and the Select button; the unpaid list lost **Outstanding**, the
   one number that page exists for. It looked like a table with fewer columns. Every
   `.nw-table` now sits in a `.nw-scroll`, which goes back to `visible` in print so the
   arrivals board still prints whole.
2. **A reversal reported a negative confirmed figure.** Reversals were written as cleared
   whatever they undid — true of the correction, false of the money. A reversal now takes
   the status of the row it reverses.
3. **Every booking at a property with VAT claimed its price had been overridden.**
   `priceWasOverridden()` compared the total against the quote, and those carry different
   things. It now compares the stay against the quote less any discount, in cents.

The lesson is the one § E already stated and this makes concrete: **a money screen that
has only been asserted has not been checked.** All three were invisible to a test that
reads the DOM, and two of them printed a wrong number to a lodge owner's face.

A fourth thing came out of the same push: a long-standing CI flake, where factory-made
region and city names relied on faker's per-test `unique()` memory to satisfy a
database-wide constraint, while the two `DatabaseTruncation` suites commit rows that
outlive that memory. Names are now unique by construction. It failed once every few
weeks in a test about taxes, which is the worst kind of failure to debug.

### 2026-08-12 — confirm and ask for the deposit, in one press

The gap between "a request arrives" and "the guest has paid" was two systems that did not
touch: the partner answered the request from an email, and the payment link lived on a
screen in the panel that only existed once the request had become a stay. A property that
wanted a deposit had to confirm, find the booking, open the drawer, copy a URL and write
their own email. That is now one button.

- **A third button in the partner's email** — "Confirm & ask for the deposit". It **opens
  a page rather than acting on arrival**, which the other two do not need: a mail client
  that prefetches links must not be able to confirm a booking and take money by opening
  the message. The page is also where the property writes an optional message to the
  guest, before anything is sent.
- **One email to the guest, not two.** The confirmation carries the payment button and the
  partner's words. A confirmation followed seconds later by a separate "and here is a
  link" is a system talking to itself. So `InquiryDecisionService` now promotes the stay
  *before* it writes to the guest — the other way round from how it was first built —
  because a payment link can only be attached to a stay that exists.
- **Asking for money can fail where confirming cannot**, so the result is carried back
  instead of swallowed (`ConfirmationOutcome`). An unpriced stay, or a request that could
  not go on the calendar, leaves the confirmation standing and the guest told — and the
  partner reads what happened rather than wondering. The partner's message still goes: it
  was written to a person, not to the payment provider.
- **The same three decisions in the panel** (`App\Filament\Partner\Support\InquiryDecisions`,
  on the request list and the request page), through the same service, so the guest gets
  the same mail whichever surface was used. Until now the booking panel could *read* a
  request and not answer one.
- **"Ask for the deposit" on an existing stay now sends** — with an optional message —
  instead of only showing a URL to copy. Copying it is still there for a property that
  would rather write their own. The old note said mailing it was "a later, deliberate
  feature"; it was right about the words and wrong about the sending, which left every
  desk pasting a link into their own mail client.
- `PaymentGateway::linkFor()` is the one call for "ask for money and give me the address",
  and it **throws instead of falling back to our own demo checkout** when a provider
  returns no redirect URL. That fallback was in the panel and was wrong: for a real
  provider that route is a 404 by design, and a page that takes a "pay" click without
  taking money is the last thing to hand a guest.

Not in this: the payment link is a link to *our* checkout, so this is the platform
collecting. A partner on somebody else's PMS whose request never becomes a stay still
answers the guest and sends their own link — see Workstream B's booking decision.

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
3. **Money that has actually moved** — all six slices of `PAYMENTS_BUILD.md` are built
   (see the dated sections above): the ledger, the invoice, commission and deposit rates,
   the three settlement models, the provider abstraction with a working demo, and the
   booking-only / full operating mode. So the sentence this entry used to carry — that the
   reservation held the whole debit side and there was no credit side at all — is no longer
   true. What is left is what code cannot finish on its own:

   - **Payouts and partner statements**, `PAYMENTS.md` §6 step 6, the one piece of the
     design not built. `SettlementBalance` already says what is owed on a stay and in which
     direction; missing are the run that aggregates it, the statement a partner reads, and
     the record of a transfer having happened. It is deliberately last: it wants real money
     to have moved before it can be tested against anything.
   - **A live gateway account.** `DpoProvider` is implemented and DPO Pay is the only
     candidate that bills and settles in NAD, but nothing has run against a real merchant
     account, and two things need DPO on the phone rather than a commit — the `ServiceType`
     for the account, and the `verifyToken` failure codes that go into
     `payments.providers.dpo.failure_codes`. Until they are known, `000` is the only code
     treated as final and everything else is reported as still pending, on purpose.
   - **Whether there is a South African entity.** Paystack works and is implemented, but a
     Namibian company cannot hold an account — that route needs a ZA entity settling in
     ZAR. A company decision, not a build task, and not made.
   - **Two commercial answers**, both cheap to give and both left open by
     `PAYMENTS_BUILD.md` § D: exactly when commission counts as earned — at confirmation,
     at the cancellation deadline, or after check-in, and what a no-show earns — and
     payment terms under the agency model, including what happens to a partner who does
     not pay a commission invoice. Neither blocks code; both are things a partner is told
     up front, so they are needed before the first one is signed.
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
6. **Staff accounts under a partner — asked for 2026-08-12, nothing built.** A listing
   partner has to be able to create accounts for their own people, with less access than
   their own. Today `users.partner_id` *is* the entire authorisation model: a user either
   operates everything their partner owns — every property, every rate, every booking,
   every customer record — or has no panel at all. There is no role, no permission and no
   per-property scope anywhere in `PartnerPanelProvider` or the page-level `canAccess()`
   checks, which all read `filled(partner_id)`.

   That is exactly wrong for the shape of the customers we are selling to. Reception takes
   bookings and reads the arrivals board; it has no business editing next season's rates,
   switching the property live, or reading another camp's guest history. NWR makes the
   per-property half unavoidable on its own: twenty camps under one partner, and a person
   works at one of them.

   What has to be decided before it is built, in the order the decisions constrain each
   other: whether the unit of access is a **role** (a small fixed set — owner, manager,
   reception — which is what an operator can actually reason about) or a permission
   matrix (which nobody will configure correctly); whether an account is scoped to
   **certain properties** of the partner as well as to a role, which the NWR case says
   yes to; who may create and disable accounts, and how an invited person sets a password
   (the `ClaimInviteService` flow is the pattern already in the repo); and what happens to
   the **audit trail** — `notes` and the reservation already freeze an author name beside
   an account id, so "who changed this rate" becomes answerable and should be, rather than
   being retrofitted later. Related: the trip plan is heading for the same problem from
   the traveller's side (collaborative plans with read-only and write access, CLAUDE.md
   → "Current focus"), and it would be a waste to invent two vocabularies for one idea.

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
  namibway.com — **which is what the booking decision below reverses**: the handover
  becomes the enquiry form on the same page, and the business sends a payment link.

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

- **The name wraps rather than being cut, and is sized per screen.** A regression
  worth recording: it was one line with an ellipsis, which on a phone left about 250px
  for a name needing 350 — and `text-overflow` does nothing on a flex container, so
  "Ongombo West #56 Hunting Safari" was severed mid-word into "…Hunting Safa" with
  nothing to signal it. It now wraps to two lines *inside* the bar's fixed height (the
  vertical padding dropped from 12px to 8px to make the room), and the size is computed
  separately for phones and wide screens, both overridable. The burger breakpoint moved
  to 1100px for the same reason: six menu items take ~600px, so at 1024 a long name has
  348px and only fits on one line at 17px, which is undersized for a laptop.

  The lesson for anything else in this bar: **measure at 320–414px, not just at
  desktop widths.** The clipping was invisible at every width the first change checked.

- **An open menu takes the bar off its photograph colours** (`.nav.is-open`, set by the
  page's own script), with the transition suppressed for that state — a 300ms fade left
  the name white on cream for exactly as long as it takes to notice.

The owner edits their own opening screen, legal text and logo from the partner panel,
through the same actions the admin uses (`EditHeroAction`, `EditLegalTextAction`,
`EditSiteLogoAction`) — the moment the two copies diverge, "the customer can also edit it
themselves" turns into two products with one price.

### Built 2026-08-12 — the content editor

The blocks were written once by `sites:generate` and could then be touched by nobody: a
typo in generated prose was unfixable, and a business with no listing had an empty frame
nothing could fill — roughly half of them have none. `EditBlocksAction` is the Website
tab's "Content" button and the same button in the owner's own panel, one implementation
serving both, like every other editor here.

What it does: bands added, reordered, switched on and off and edited, with a form per
block type. Filament's Builder rather than a repeater, because the whole point is that each
type carries different fields.

Four decisions worth not rediscovering:

- **The Filament fields are not on the block classes.** `App\Sites\Blocks` is domain code
  serving the public renderer, and a panel component in there would sit on the path of every
  page we serve. They live in `App\Filament\Support\Sites\BlockForm` instead. The cost of
  that split is drift, and it is paid by `BlockEditorTest`: every type in the registry has a
  form, every field on a form is a key that type's own `rules()` accept, and only a key the
  definition names in `richTextFields()` may reach a rich editor. A test, not a sentence
  asking somebody to remember.
- **The purifier was missing, and the views had been saying otherwise.** `about` and
  `rich_text` render their body with `{!! !!}`, and the comment above that line claimed the
  value had already been sanitised. It had — but by provenance, not by anything in this
  code: the only way to fill it was to copy a listing description that went through
  `Listing::sanitizeRichText` on its own way in. An editor is a person typing, so `SiteBlock`
  now runs the keys a definition declares as rich text through the same allow-list before it
  writes. Anything else is text and the view escapes it.
- **One block of each type per page**, refused with the type named rather than silently
  dropped. Generation resolves a block by its type (`firstOrNew(['type' => …])`), so two
  galleries would make a rebuild pick one of them arbitrarily — on somebody's live shopfront.
- **`is_enabled` rides inside the builder item and is taken back out on the way in.** It is a
  column and not part of the payload, but a switch outside the builder could not follow a
  band being dragged.

Rebuild protection needed nothing new: `sites.imported.blocks` already records what
generation wrote, so an edited block is left alone by the next `sites:generate` for free.

It shipped without a picture *upload* and without a way to make a second page. Both were
closed the same day (below).

### Built 2026-08-12 — uploading pictures

`EditSiteImagesAction`, on the Website tab and in the owner's own panel: upload, describe,
reorder, remove. It closes the gap the editor shipped with — a band chooses a picture from
the site's own `site_images` by id, and until now the only thing that could create one of
those rows was generation. A customer with no listing therefore had an editor with an empty
picture list, which is half the customers on the flyer.

Three decisions inside it:

- **An uploaded picture is `ContentSource::Partner` and not `prospect_only`.** Somebody put
  it there on the business's behalf, which is the top of the content ladder — unlike a
  Google Places photograph, which is publishable on namibway.com under Google's terms, is
  not ours to hand a customer, and blocks publication (`PublishGate`). The modal says the
  condition out loud: only pictures the business owns or has the right to publish.
- **A saved row is updated, never replaced.** The blocks point at pictures by id, so a save
  that recreated rows would quietly empty every band that used one.
- **Removing a picture takes it out of the bands that used it** — `image_id` cleared,
  `image_ids` filtered — because a band pointing at a picture that is gone renders a gap.
  The row goes and **the object stays**: deleting bytes out of a shared bucket on a form
  submit is the kind of thing that is only ever discovered later, and `photos:audit-r2` is
  what collects what nothing references any more.

### Built 2026-08-12 — more than one page

`EditPagesAction`, on the Website tab and in the owner's own panel: add, rename, reorder and
remove pages. The renderer could always serve them — `SiteController` resolves a slug against
`site_pages` and the route already carried `{page?}` — but nothing could make a second one,
so every site was a single scrolling page whether or not that suited the business. A guest
house is fine that way; a tour operator with eight itineraries is not. The content editor
gained a page picker rather than a second modal: switching reloads the bands under it.

What is enforced, rather than left to care:

- **The front page cannot be removed and cannot be moved off the root.** It is what answers
  at the root of the domain, what the draft link points at and what `sites:generate` writes
  into. Its title is editable, its slug is not, and a saved state that had somehow lost it
  leaves it standing.
- **A slug the site answers itself is refused** — `privacy` and `legal` are rendered from the
  site record rather than from a page, and `robots.txt`, `sitemap.xml` and `enquiry` are
  answered before any page is looked up. A page at one of those addresses would save and then
  never appear, which is worse than being told no. Two pages sharing an address are refused
  for the same reason.
- **A removed page takes its blocks with it.** They are its content and belong nowhere else.

**The menu is capped at six, pages included, and that is load-bearing.** The bar has a fixed
height and the hero is pulled up under it by exactly that many pixels, so a menu allowed to
grow leaves a strip of page background above the photograph — which happened once already and
is why the cap has a test of its own now. Pages come first and the current page's anchors
fill what is left: getting to another page matters more than jumping within this one, and a
visitor who cannot see the other pages cannot know they exist. Links go through
`Site::pageUrl()`, so a draft keeps its `?preview=` token rather than sending whoever is
reviewing it to a 404.

The sitemap needed nothing: it already walked every page of the site's default locale.

### Built 2026-08-12 — our own terms, and the business confirming them

Two halves of one thing. `config('sites.terms_url')` was empty, so the foot of every
customer site and the publish confirmation both linked to nothing; and the confirmation was
ticked by us on the business's behalf, which records what we were told rather than what they
did.

- **The terms are versions in a table** (`website_terms`, edited in Settings → Website
  Terms), not a settings row and not a config string. A customer's acceptance is recorded
  against a moment, and editing one text over and over would make every past acceptance
  unanswerable — so `sites.terms_version` now stores which text was agreed, and an old
  version stays readable. Publishing is what makes a version the one customers are shown;
  an unpublished row is a draft.
- **The first draft is written in-house and starts unpublished** (`App\Sites\WebsiteTermsText`).
  It is a scaffold for a lawyer, not a reviewed contract, and it says so in the panel in
  front of whoever edits it — and deliberately **not** on the public page, where terms
  announcing their own uncertainty would be useless and alarming at once. Every clause
  describes how the product actually works today, which is the part an outside lawyer cannot
  know and the part that takes longest to explain. It follows decisions already recorded
  here: the customer owns and answers for the content, we answer for hosting and technology,
  the content leaves with them, and a booking is between the guest and the business.
- **The business confirms from an email** (`SiteTermsConfirmation` → a signed link →
  `SiteTermsController`). The page shows the site, the privacy and legal notices, and our
  terms where one is published; confirming records who and when and against which version,
  and publishes the site. The GET only shows and the POST acts, because a mail client that
  prefetches links must not be able to publish somebody's website by opening the message.
  A publish the gate refuses does not lose the confirmation — it is recorded first and the
  failure is reported to us, not explained to the owner.
- Plain Blade rather than Inertia for both public pages: they are reached from a customer
  site's footer or from an email by somebody who may never have seen the platform, and a
  JavaScript bundle adds nothing to a page whose whole job is to be read.

The checkbox on the publish action stays for the case it was written for — somebody
confirming on the telephone while we are on the call.

### Built 2026-08-13 — book, call, WhatsApp: the three action buttons

The booking link was the last item in the menu, which on a phone put the one thing the
site exists to do behind a burger, under "Opening hours". `App\Sites\Rendering\SiteActions`
now resolves the three actions once per page and three places render them: a button in the
top bar (from 640px up), a pair under the hero headline, and a strip fixed to the foot of
the screen below 1100px — call, WhatsApp, and the primary action, at thumb height on every
screen of the site.

What is worth knowing later:

- **The primary action degrades: booking → enquiry → contact.** The booking band renders
  only where the property has sellable inventory with us, and the enquiry form only where
  we hold a listing — so on most sites neither exists, and a template whose main button is
  missing two thirds of the time is not a template. The contact fallback is labelled
  "Contact" rather than the band's own heading, because a contact section is usually headed
  "Find us", which on a button reads as directions. It is also the one case dropped from
  the foot strip when Call or WhatsApp is already there: a third button meaning "the same
  two things, further down the page" is noise on the surface with least room for it.
- **The hero gets a button without anybody typing one in.** Generation leaves
  `cta_label`/`cta_href` empty — nothing knows the anchors at that point — so a generated
  site used to open with a headline and no way to act on it. An owner's own label and
  target still win over the derived one.
- **Each button has a switch on the site.** Superseded the same day by placements — see
  the next entry — which is why nothing here says which one is on by default any more.
- **The strip makes room for itself with a spacer element, not with padding on the body**,
  so the thing that covers the foot of the page and the thing that clears it can never
  exist without each other.
- The one CSS trap met on the way: `.nav__cta` is also a `.btn`, `.btn` is declared later
  in the same stylesheet, and at equal specificity the later rule wins — so the selector is
  deliberately `.nav .nav__cta`.

### Built 2026-08-13 — where each button goes, per screen

The morning's version had one switch per action and the renderer decided where the button
went. That was the wrong half of the decision, and a screenshot of the first real site
showed why: an "Enquire" button in the menu bar, redundant beside the "Request availability"
band it points at, pushing "Ongombo West #56 Hunting Safari" onto a second line. The same
button at the foot of a phone screen was exactly right.

So the unit is a **placement** — an action, an area, a device — and there are four actions
(enquiry, WhatsApp, call, and one the business writes itself), three areas (menu bar,
opening screen, the strip at the foot of the screen) and two devices. `App\Sites\ActionButtons`
holds them in one JSON column with the labels; `App\Sites\Rendering\SiteActions` turns them
into buttons for a page; `EditActionButtonsAction` is one section per action.

The defaults are the product decision, and read as a sentence: the enquiry button under the
headline on a desktop and at the foot of the screen on a phone; WhatsApp and the telephone
at the foot of a phone screen, because both are phone things — WhatsApp especially, which
is how this market answers and is close to useless on a laptop; the business's own button
("About us" until they change it) under the headline on both. **Nothing in the menu**, which
is a list of places on the page and not a place for a button.

What is worth knowing later:

- **A placement never renders a broken button.** A WhatsApp button on a site with no
  WhatsApp number, or an enquiry button where there is nothing to enquire about, is not
  rendered however it is ticked — that check is in the resolver, not in the panel, so it
  cannot depend on somebody remembering.
- **The screen is decided in CSS, not on the server.** The page is rendered once carrying
  both, and `.at-phone` / `.at-desktop` hide what the screen is not for; a server that reads
  the device off a user agent serves the phone layout to a laptop sooner or later. 1100px is
  the only "this is a desktop" number in the stylesheet, and it is the same line the menu
  uses to stop being a burger.
- **The strip at the foot follows its buttons**, including onto a desktop if somebody puts
  one there, and takes its own height out of the flow with a spacer that carries the same
  visibility class — so it can never clear space on a screen where it is not shown.
- **Two titles became editable, both with a line break that is honoured.** The opening line
  is the only text on the site set at 76px, so where it breaks is a decision, not something
  to leave to a browser; and `sites.brand_name` is the name *the bar* sets, separate from
  the business's name, which stays whole in the page title and the legal notice. A
  hand-broken name is short, so the automatic sizing wants to set it larger — and two lines
  of 22px are 53px in a bar that leaves 48. Capped, after measuring it clip.

### Built 2026-08-13 — the button the scroll brings in

Three notes off a screenshot of the live site, all the same shape. The opening screen
carries the enquiry button and then scrolls away with it, and from that moment the visitor
has nothing to press without scrolling back or opening the burger. So:

- **The bar picks it up once the page has scrolled.** On a wide screen it swaps for the
  menu item that says the same thing, so what the eye sees is that item turning into a
  button; on a burger-width screen there is no item to swap and it simply appears beside
  the burger. Below 640px nothing appears — the strip at the foot of the screen is already
  carrying it and the bar has a name and a burger and no room for a third thing.
- **It is in the markup from the first byte and hidden**, rather than built by the script.
  A button that appears by being unhidden costs one repaint; one built in JavaScript costs
  a layout of the whole bar at the exact moment somebody is scrolling.
- **`is-scrolled` is now put on every page**, not only the ones with a hero to scroll off.
  It used to mean "the bar is over the page rather than over a photograph"; it now also
  brings in a button, which is wanted wherever the opening screen has gone.
- **The enquiry item is last in the menu** — "Request availability" after "Get in touch" —
  because it is the one item that is an action rather than a place, and because that is
  where it turns into a button.
- A business that has *placed* the enquiry button in the menu keeps it there from the first
  pixel and gets no second one.

### Built 2026-08-13 — the subscription, and a button to order one

The create-website button in the partner panel had been switched off since the day it was
written, with a tooltip saying to talk to us. That is a dead end on a screen: it tells
somebody the product exists and gives them nothing to press. Now there is an **Order a
website** button beside it, and the lock opens by itself when the subscription is active.

- **An order is a request, not a purchase.** There is no payment provider for this market,
  billing runs by invoice, and a person at NamibWay decides they are a customer. So ordering
  creates a `requested` subscription, mails the team, and says plainly to the owner that
  nothing has been charged.
- **Four states and no more** (`SubscriptionStatus`): requested, active, suspended,
  cancelled. Only *active* entitles anything. Suspending takes the entitlement away and
  leaves the website exactly as it is — the content is theirs, and holding it hostage over
  an invoice is not the product.
- **Nothing in it names a price, a currency or a gateway**, and a test asserts that of the
  columns. The price is a property of the offer and not of one customer; a column here would
  be a per-customer price nobody decided to have, and the first discount typed into it
  becomes a commitment nobody remembers making. Whatever a provider needs later is its own
  table, keyed to this one.
- **One subscription per partner**, as a unique index rather than as care: the fee buys the
  business a website, a partner with two lodges is one customer, and a second row would make
  "are they entitled?" a question with two answers. Ordering twice returns the same row.
- **The check is behind the button as well as on it.** A partner reaches the build action
  through a Livewire call from their own browser, and a disabled attribute is something the
  browser was asked to render rather than something it can be held to. The admin is
  deliberately not subject to it — we build drafts for prospects who have subscribed to
  nothing, which is how the product is sold.
- **`activated_at` is written once.** A subscription switched back on is the same customer,
  and the date they became one does not move.

Billing is still a person reading Settings → Website Subscriptions, invoicing the new ones
and pressing Activate. That is the decision this was built against: the state machine now,
the collection behind it once a provider for this market exists.

### Built 2026-08-16 — one contact form per site, chosen by the owner

The form on a customer's website used to have a `mode` of `stay` or `visit`, derived from
the business type at generation and never changeable. That distinguished two of the five
things these businesses actually ask their visitors for. It is now an
`App\Sites\Blocks\EnquiryFormType` the owner picks: **contact**, **reservation request**,
**table reservation**, **restaurant order**, **product order**.

- **Exactly one per site, as a select and not as toggles.** A page offering a table booking
  *and* a product order *and* a general contact form has not decided what it sells, and
  every extra choice costs the visitor the one they wanted.
- **The type is the block's, never the browser's.** The form posts a hidden `form_type`, and
  the controller ignores it: it reads the block and validates against that. Which fields are
  required — and which are dropped — follows from the type, so an order can never carry a
  check-in nobody asked for. Same discipline as `ListingController` on namibway.com.
- **Email or WhatsApp, never both.** One channel, chosen with the type. The form used to
  render a submit button and a WhatsApp button side by side, which asks the visitor to pick
  a medium before they have said anything.
- **The menu button has its own label.** `SiteActions` derived it from the heading and
  capped it at 14 characters, which is how "Request availability" became "Enquire" on every
  site whether or not that was the word. There is a per-type default now
  (`EnquiryFormType::buttonLabel()`) and a field the owner can override it in.
- **The date range is one control on the page and two columns underneath.** Till asked for
  the space-saving single field the listing search has; the confirmation mails and the
  calendar are built on `check_in` / `check_out`, and free text would lose both.

Three things underneath it that were not cosmetic:

- **An inquiry can hang off a partner instead of a listing.** `inquiries.listing_id` was NOT
  NULL and the enquiry controller 404'd a site without one — so a shop that never listed on
  the travel platform, which is the case the product catalogue was written for, could
  receive nothing at all. There is a nullable `partner_id`, a CHECK constraint that a row
  names one or the other, and `Inquiry::seller()` / `sellerName()` / `sellerEmail()` as the
  one reader. A dozen call sites read `$inquiry->listing->name` and fatalled the moment a
  request had no listing; they read the seller now, mail views included.
- **`shop_products.price` is a number.** It was free text, so an order could not be totalled
  and the catalogue sorted "N$ 1 200" before "N$ 350". The old text survives as `price_text`
  and is shown where there is no number — "Call for price" is a real thing a shop says — but
  an unpriced product is not orderable, and the form does not offer it a quantity.
- **The deposit button is gone from anything that is not a stay.** Confirming an order
  offered "Confirm & ask for deposit", which confirmed it and then told the business the
  confirmation "could not be put on the calendar" and that the team had been alerted, over a
  request that behaved perfectly normally. Hidden in the panel, dropped from the mail, and
  404 on the signed route, because a link already sent stays valid.

**Not built: taking money for an order.** That is a payment intent hanging off a request
rather than off a reservation, and the money side has no such thing — `payment_intents`,
`payments`, the folio and the invoice are all keyed to a reservation. It is the same open
question the subscription raised (see the payment-boundary note in `WEBSITE_BUILDER.md`):
either the ledger grows a second kind of thing to be paid, or these are invoiced outside it.
Until that is decided, not offering the button is the honest answer rather than offering one
that fails.

### Fixed 2026-08-16 — the site follows the restaurant's own switches, and names the form once

Two faults found on a real site the same day, both of them the same mistake in two places:
the form type was **stored once and never asked again**, and the form had **two names**.

- **The block's type is corrected against the listing at render.**
  `EnquiryBlock::formTypeFor()` is what every reader calls now — the page, the menu, the
  buttons and the POST. A restaurant that has switched `accepts_orders` on and
  `accepts_table_reservations` off is offered ordering, whatever the block says, because a
  form the platform would refuse is a promise the page cannot keep. Only the two restaurant
  types are corrected and only towards something the listing allows; a contact form, a stay
  request or a product order is the owner saying what their page is for and is left alone.
  Deliberately *not* gated on `accepts_inquiries` — that switch is about namibway.com, and a
  business's own website is a second front door that stays open.
  `SiteGenerator::restaurantFormType()` reads the same two switches at generation, so a new
  site starts where an old one is corrected to.
- **Neither switch on is a plain contact form**, not a broken booking one. Walk-ins are a
  real way to run a restaurant. Ordering additionally needs a non-empty menu, the same rule
  `Listing::requestKinds()` applies.
- **One target, one name.** The menu item was labelled from the block's own heading while
  the button beside it came from `SiteActions`, so a site read "Request availability" in the
  menu and "Book a table" on the button — both scrolling to the same form. The nav asks
  `SiteActions::enquiryLabel()` now. And a heading that is only one of the generated
  defaults follows the resolved type (`EnquiryBlock::heading()`), so a restaurant switched
  to ordering does not keep a band headed "Book a table" over a form asking for food. A
  heading the owner wrote is never touched.

No migration: the correction happens at render, so it applies to every site already
generated without a write, and it keeps applying when a business changes its mind again.

**Corrected the same day: an empty menu no longer changes the form.** The rule above
originally also degraded a restaurant-order form when the menu had no dishes in it, on the
reasoning that an order form with nothing to order is a promise the page cannot keep. In
front of a business setting its site up that reasoning is backwards — the menu is empty
*because they are in the middle of filling it* — and it cost three days of "I picked
ordering and the page shows a contact form". Only the two switches decide now; the page
renders the order form with no items yet and the dishes appear the moment they exist. Two
things went with it: a typed **menu-button label is dropped when the type it was written
for has been corrected away** (the bar read "Order online" over a contact form, which is
what made the page contradict itself), and the WhatsApp message now carries the visitor's
**phone number**, which was collected and then left out of it — on a food order it is the
one thing the shop needs.

**Followed the same day: the editor says why.** Degrading quietly is right in front of a
visitor and useless to the owner — somebody picked "Restaurant order", pressed Save, and got
a plain contact form with no explanation on any screen. `EnquiryBlock::unavailableReason()`
answers it where the choice is made, naming the thing that is missing: the menu is empty,
the switch is off on the listing, the shop has no priced products. A select that accepts a
choice it will not honour has to say so. The heading field says its own rule too — one of
the standard headings follows the form type, anything typed stays exactly as typed.

### Next up, in the order it was asked for

- **Collecting the money.** A provider that onboards a Namibian entity and settles in NAD,
  plugged in behind the subscription rather than into it. Until then a person invoices and
  presses Activate, which is what the state machine was built to allow.
- **Paying for an order.** A restaurant order and a product order can be placed and priced;
  neither can be paid for, because a payment intent is keyed to a reservation and an order
  never becomes one. Decide whether the ledger takes a second kind of payable thing or these
  are invoiced outside it, then the "send a payment link" button belongs in the partner
  panel and in the confirmation mail.
- **Delivery for a restaurant order.** A product order asks for a delivery address and a
  restaurant order deliberately does not, because ordering food to an address is not yet how
  this market works — a Kapana stand is collected from. That is a statement about today, not
  about the shape of the thing: food delivery is plausibly large here later, and it is one
  switch plus one address field away, since the address already exists on the product side
  (`EnquiryFormType::needsAddress()`, and `SiteEnquiryController::message()` puts it in front
  of the message). What it needs before it is worth building is the part that is not a field:
  a delivery area, a fee, and some idea of who carries the food. Do not add the address to
  restaurant orders as a quiet default in the meantime — an address box on a form for a stand
  people walk up to is a question with no good answer.
- **A business directory, possible rather than decided** (noted 2026-08-17) — a public listing
  of every partner and their products on its own domain, **NamibWay.na** or
  **NamibBusiness.com**, independent of tourism listings and aimed at locals as much as
  travellers. Not to be built yet, but it needs several things the partner side does not have
  — a partner has no public identity of its own, there is no trade taxonomy, and products are
  scoped to a site rather than to a partner. The list, and what not to make harder in the
  meantime, is in `WEBSITE_BUILDER.md` § 5a.
- **Multilingual sites** — EN, DE, NL, FR, ES. `site_pages.locale` is the foundation and not
  the feature: there is no switcher, no per-locale routing, and the renderer reads
  `default_locale` and nothing else.

### Decided 2026-08-12 — the answers slice 2 builds against

Every question this section listed as open has now been answered. Recorded here in the
form they were given, with the consequence each one has for the build.

- **Booking on the customer's site is the enquiry, and the payment is a link the business
  sends.** This supersedes the answer first recorded here the same day, which had the
  guest signing in to NamibWay from the tenant site through an OAuth-shaped popup so that
  a booking could complete in place. That is not being built.

  What replaces it: the guest picks dates, sees the live quote out of `RoomOffers`, and
  presses a button that fills in the enquiry form **on the page they are already on**.
  From there it is the pipeline that already exists — the `Inquiry`, the mail to the
  business with the signed confirm and decline links, the copy to the guest. The business
  then decides how it wants to be paid and sends a payment link, by hand or triggered off
  the confirmation.

  Worth being precise about, because the earlier note recorded "cross-domain" as the hard
  part: this is not a problem now solved, it is one no longer had. There was never a CORS
  question — CORS governs a page reading another host's response with JavaScript, and
  nothing on these pages does that. The obstacle was the session cookie, and a cookie is
  only needed because a *login* was in the design. Take the login out and the tenant site
  stays exactly what it is: no session, no CSRF token, no token exchange, no second host
  in the byte budget. It settles the `ActiveRequestGate` question by not raising it — an
  enquiry from a business's own website already runs outside that gate.

  What it costs, plainly: the platform does not touch the money, so nothing automatic can
  be built on top of it — no commission taken at the point of sale, no cancellation window
  we enforce, and the terms attached to the payment link are the business's own. That is
  the trade accepted for a booking flow that can go live without a payment provider for
  Namibia existing, which question 7 below still has no answer to.

  One code consequence to carry into the next slice: the booking block ends today in
  "Request these dates" pointing at `url('/listings/'.$slug)`
  (`App\Sites\Rendering\BookingPanelData::bookingUrl()`), which sends the guest off the
  customer's site to namibway.com — the opposite of this decision.

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
7. **Websites: how is N$ 399/month actually collected in Namibia?** **Half answered
   2026-08-12.** The *provider* question is settled as far as code can settle it — DPO Pay
   by Network operates in Namibia and settles in NAD, and `DpoProvider` is built and
   configured by env. What is still open is the commercial half: a merchant account needs
   NamibWay's Namibian entity, and a monthly N$ 399 is a **recurring** charge, which is a
   different thing from the one-off payment the booking system takes — DPO's recurring
   support has to be asked about rather than assumed. Manual invoicing still bridges the
   gap and the subscription state machine gets built against it regardless. See §4.
8. **Money: when is commission earned, exactly** — at confirmation, at the cancellation
   deadline, or after check-in — and what does a no-show earn? Added 2026-08-12 from
   `PAYMENTS_BUILD.md` § D. There is a sensible default in the code; the answer is
   commercial and is one of the things a partner is told up front.
9. **Money: payment terms under the agency model**, and what happens to a partner who does
   not pay a commission invoice. The only technical lever is `Partner.booking_enabled`,
   and pulling it is a business decision, not a feature.
10. ~~Both: is the website builder allowed to read from `Listing`, or are the two kept
    apart?~~ **Answered by the build** — a listing seeds a site once, by copying, and is
    never read at render time.

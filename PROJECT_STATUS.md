# Project status and what's next

**Written 2026-08-10.** This is a snapshot plus direction, meant to brief a fresh
session that will pick up one of the two new workstreams. `CLAUDE.md` holds the
standing rules and the architecture — read that first; this file says where things
actually stand and what has to be decided before the next thing gets built.

Add a dated line when something here changes state. A status file that quietly goes
stale is worse than none, because the next session will trust it.

---

## 1. Where the platform stands

Live in production, auto-deploying from green `main`, nightly encrypted backups.
The MVP foundation is done; the work is depth, not scaffolding.

Three business lines now exist, and only the first has software behind it:

| Line | State |
|---|---|
| **Travel platform** (namibway.com) | In production. Kaia interview → trip plan → booking requests. The flagship is the trip plan — see `TRAVEL_PLAN.md`. |
| **Websites for Namibian businesses** | Sold, not built. Flyer exists (N$ 399/month, all inclusive). No product, no tenancy, no builder. Workstream B below. |
| **Custom software / booking system** | Sold as a proposal to NWR. Substrate exists, the lodge-facing product does not. Workstream A below. |

Marketing material for all three lives in `marketing/` and is downloadable from the
admin panel under **Documentation → Marketing material**. `marketing/README.md`
records what we may and may not claim — that list is load-bearing, because the
booking-system flyer is addressed to a named organisation.

---

## 2. What the booking substrate actually is

**Read this before designing anything in workstream A.** These are verified from the
code, not from memory, and several of them are the reason the lodge system is a real
build rather than a UI on top of what exists.

> **Updated 2026-08-11.** The four bullets below marked ✅ describe what the
> *traveller-facing* flow still does, and that has not changed. What changed is that a
> lodge-facing ARI substrate now exists beside it (`App\Services\Inventory`) — see the
> 2026-08-11 entry in section 3. Read both: the two are deliberately separate, and
> confusing them is the mistake this note exists to prevent.

- **`Inquiry` is the booking record for the traveller-facing flow.** Its statuses are
  request-shaped, not stay-shaped: `pending`, `processing`, `on_request`, `nwr_pending`,
  `confirmed`, `cancelled`, `failed` (`App\Enums\InquiryStatus`). Stay-shaped states now
  exist separately as `App\Enums\StayStatus` on `Reservation`; nothing bridges the two yet
  (the design for that bridge is written down in `CLAUDE.md`).
- **Traveller-facing availability is still derived, never stored.**
  `App\Services\Booking\RoomAvailability` answers "how many units are free" as
  `total_units` minus the overlapping inquiries in `on_request` or `confirmed`, and it
  still drives the trip plan's room picker. A calendar table now exists
  (`room_type_calendar_days`) but **is not wired into this path** — pointing the picker at
  it is a later, deliberate step, guarded by a test.
- **One inquiry is still implicitly one unit.** The `inquiries` table has no quantity
  column. A `Reservation` can hold several room types with quantities; an `Inquiry`
  cannot.
- **`RoomType` still carries a single flat `rate_per_night`** plus `total_units`,
  `max_adults`, `max_children` and a string `code` — but those are now *defaults*, and the
  calendar overrides them per night, which is where seasons live.
- **Soft holds exist**: `inquiries.hold_expires_at` with `ExpireNativeHoldJob` releasing
  the hold and mailing the guest, idempotent and status-guarded.
- **Partner response is one click**: signed confirm/decline URLs (`routes/partner.php`)
  and the same transition from the partner panel, both through `InquiryDecisionService`.
- **The partner panel is thin**: `app/Filament/Partner/Resources` has exactly two
  resources, Inquiry and Listing. No calendar, no arrivals list, no way to enter a
  booking that did not come from the website.
- **Connectors exist and none are validated.** `ResConnect`, `NightsBridge`, `HopeCloud`,
  `Nwr`, `Native`, `Wetu`, plus manual. Not one has run against a real partner account.
  `NwrConnector` is deliberately a concierge stub: NWR has no API, so availability always
  returns "on request" and the team checks manually.

The consequence, as of 2026-08-10: the traveller-facing model can express *"a traveller
asked for a room and a partner said yes"* and nothing more. Everything it could not
express — a seasonal rate, a maintenance block, a booking of three rooms, a walk-in, a
guest standing at a desk — is what the inventory substrate added on 2026-08-11. What is
still missing is every screen that would let a human use it.

---

## 3. Workstream A — a booking system lodges can operate

**Goal:** a booking system that lodge staff operate themselves, with NWR as the first
partner we try to connect. The flyer we hand them proposes a pilot on **one camp for one
season, running alongside what they use today** — that promise should shape the build.

### What is missing, concretely

Ordered so that each item depends only on the ones above it:

1. ✅ **A calendar table.** `room_type_calendar_days` — per room type, per night: units,
   rate, minimum stay, closed-to-arrival, closed-to-departure. Sparse, with null meaning
   "follow the room type's default". Done 2026-08-11.
2. ✅ **Quantity per booking** — `reservations` + `reservation_units`: several room types
   with quantities under one guest. Done 2026-08-11.
3. ✅ **Blocking** — `inventory_blocks`, counted separately from sales so an occupancy
   view can tell "sold out" from "taken off sale". Done 2026-08-11.
4. ✅ **A stay lifecycle** — `App\Enums\StayStatus`: provisional, confirmed, due-in,
   in-house, checked-out, no-show, cancelled, cancelled-late, with the legal transitions
   enforced in `InventoryWriter`. Done 2026-08-11.
5. 🟡 **Front-desk surfaces**: reading and doing are both built now — an occupancy
   calendar, an arrivals/departures board, manual booking entry, the stay lifecycle,
   block editing and a bulk rate editor (2026-08-11, below). What is still missing is
   room-level assignment, for a lodge that assigns real rooms rather than room types.
   That is deliberately *not* modelled yet: a reservation holds room types and
   quantities, never a named room.
6. ✅ **Multi-property under one partner.** NWR is one partner with many camps. The
   partner panel now has a property switcher in its topbar, scoping the lodge-facing
   screens; the existing Listing and Inquiry resources are unchanged and still show
   everything the partner owns. Done 2026-08-11.
7. ⬜ **Money**: what a stay costs, what was paid, what is owed. A `Reservation` now
   carries a `total_amount` and a per-night breakdown (`reservation_nights`), so what a
   stay *costs* is answered. What was paid and what is owed is not — there is no folio
   and no payment collection, and Stripe remains Phase 2.

### 2026-08-11 — inventory substrate (slice 1 of 3)

Domain layer only, no UI. Standard-shaped rather than bespoke: **ARI** (Availability,
Rates, Inventory), the model NightsBridge, ResRequest and every channel manager speak, so
a future connector is a mapping rather than a translation. The standards rule this came
from is now written down in `CLAUDE.md` → "Standards".

What landed:

- `room_type_calendar_days`, `reservations`, `reservation_units`, `reservation_nights`,
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
with the prospect's own lodge running rather than an empty grid. It gets its **own
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
> inside it at all. Every address it does hold is under `config('booking.demo.email_domain')`,
> and a test walks the tenant asserting exactly that.

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

These change the shape of the system, so they are worth asking the user directly:

- Is this a **reservation system** (bookings and availability only) or a **PMS** (in-house
  guests, folios, housekeeping)? The flyer implies the former; "bedienbar in Lodges"
  could mean either.
- Do lodge staff need it to work **offline**?
- Who owns the **rate calendar** — do they maintain rates in our system, or do rates
  arrive from theirs?
- For the pilot, does our system take **all** bookings for that camp, or only ours?

### Suggested first slice — done, and what follows

The calendar table plus per-date rates and quantity landed on 2026-08-11 (above). What
that slice deliberately left is the half a human touches: a partner-panel property
switcher and an arrivals/departures view, with NWR modelled as one `Partner` and its camps
as `Listing` rows. Together with the substrate that is enough to run one camp for one
season without touching the traveller-facing flow, which is what the flyer promises.

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

---

## 6. Consolidated open questions for the user

Nothing below is blocking today's work, but each one changes what gets built:

1. Booking system: reservation system or full PMS?
2. Booking system: must the front desk work offline?
3. Booking system: during the pilot, do we take all bookings for that camp or only ours?
4. Booking system: who maintains the rate calendar?
5. Websites: subdomains only at first, or custom domains immediately?
6. Websites: does the customer ever edit, or is it always us?
7. Websites: how is N$ 399/month actually collected in Namibia?
8. Both: is the website builder allowed to read from `Listing`, or are the two kept apart?

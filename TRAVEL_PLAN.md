# Reiseplan — working notes

This file tracks the ongoing polish of the **Reiseplan** (trip planning &
booking flow) — the core, flagship feature of NamibWay. Per `CLAUDE.md`,
this is meant to become the "WOW" showcase feature: collaborative, simple,
intuitive creation of a complete trip plan including booking, then
after-sales support while the trip is actually happening. It has to work
well on desktop and mobile (browser, PWA, native app).

There's a lot of ground between where it is today and that bar. We're
working through it step by step, across multiple sessions, tracked here so
nothing gets lost between sessions.

## Where the code lives

- `resources/js/components/home/ItinerarySection.vue` — the orchestrator:
  section head/summary, per-variant loop (trip meta, vehicle, map, the
  draggable day list, save/share), almost all itinerary state and handlers.
- `resources/js/components/home/TripMap.vue` — the Leaflet route map.
- `resources/js/components/home/ItineraryLineItem.vue` — one
  accommodation/activity/restaurant/vehicle line (name, price, swap/remove).
- `resources/js/components/home/AlternativesPanel.vue` — the "swap" list
  used by all four of the above.
- `resources/js/components/home/RoomTypePicker.vue` — the "Zimmer wählen"
  panel. Fetches real room types for the stay's dates from
  `/listings/{slug}/room-types`; shows an "the partner confirms the room"
  note when a listing has none, which is currently every listing.
- `resources/js/components/home/TripMeta.vue`, `TripParamsEditModal.vue` —
  trip-level params display/edit (regenerates the plan via Kaia).
- `app/Services/Kaia/ItineraryService.php` — builds/resolves the plan
  server-side: Claude tool-use schema, listing resolution, alternatives,
  routing/driving-distance guidance, availability pre-checks.
- `app/Models/Listing.php`, `BookableUnit.php` (was `RoomType.php`), `SavedPlan.php` — the backing
  data. `RoomType` holds per-listing room/unit types; availability is derived
  by `app/Services/Booking/RoomAvailability.php`, which both the Native
  connector and the plan's room picker go through. Edited via the
  `RoomTypesRelationManager` on the listing in the admin *and* partner panels.

## Future concept: collaborative trip plan (partly built — see session 5)

A trip is rarely planned alone. The plan should be shareable with fellow
travelers who can **join the planning**, not just look at the result:

- **Share read-only or with write access** — two different link levels (or
  per-person grants), not today's one-link-does-everything.
- **Co-planning** — a participant with write access can do what the owner
  can: swap items, reorder days, edit trip params.
- **Comments** — on the plan as a whole and on individual items/days
  ("do we really want two nights here?"), with **follow-ups** (replies on
  a comment, and a resolved/open state so a settled question stops
  cluttering the plan).
- **Log** — a visible history of who changed or commented on what, and
  when. This is what makes shared editing safe: without it, a co-planner's
  change is indistinguishable from a bug.

Where this collides with what exists today:

- ✅ *Fixed in session 5.* A plan now has two tokens: `token` (edit) and
  `share_token` (read-only, rejected by `updatePlan`). The Share button, the
  PDF link and the `/trip` page all hand out the read-only one, and a
  read-only view renders the plan with no writing affordances at all. So
  there *is* a read-only mode to give someone now.
- ✅ *Fixed in session 5.* `plan_json` is still overwritten wholesale, but a
  `version` counter makes a stale write fail with a 409 instead of silently
  clobbering, via a single conditional `UPDATE ... WHERE version = ?`. Note
  this is conflict *detection*, not merging — the losing editor is told to
  reload. Item-level writes are still the real answer for simultaneous
  co-editing.
- ⬜ Still true: nothing is attributable. `user_id`/`session_id` are recorded
  but never checked, and a change leaves no trace of who made it, so the log
  has nowhere to come from yet. This is the next real step — and the one
  that needs identities, not just tokens.

Decided — where the account line sits:

- **No account:** viewing and editing the plan that just came out of the
  Kaia chat (swap items, reorder days, edit trip params), via the
  `SavedPlan` token. This path stays frictionless — it's the product's
  first impression, and the login requirement was deliberately dropped
  here (see "Drop login requirement from trip-plan sharing").
- **Account required:** saving a plan to your account, **collaborative
  editing** (a co-planner needs an identity — that's also what makes the
  change log meaningful), and booking.
- **Only the creator can book, for now.** Sharing a plan for co-planning
  does not hand over the ability to send booking requests. This keeps the
  one-active-request-per-traveler rule in `CLAUDE.md` coherent: exactly one
  responsible person per booking pipeline.

✅ *All three enforced as of session 6.* This used to be a statement of intent
with no server behind it; it is now what the code actually does:

- Creating and editing a plan still need no account (the autosave path is
  untouched), but an anonymous request can only ever produce an *unowned* plan.
  Attaching one to an account goes through `KaiaController::claimPlan`, which
  is behind `auth` — that's the account line, and it's the only thing that
  writes `SavedPlan.user_id`.
- `ListingController::storeInquiry` / `storeBatchInquiry` and
  `TripController::store` are all behind `auth`.
- "Only the creator can book" resolves from the plan token the booking is made
  against: it must be the plan's *edit* token, and a plan that already has an
  owner may only be booked by that owner.

Still open:

- Live/simultaneous editing, or is "refresh to see changes" acceptable for
  v1? Live collaboration is a much bigger build (broadcasting, presence).
- Beyond booking, what else stays creator-only — revoking access, deleting
  the plan, changing trip params that invalidate others' work?
- Partly answered in session 6: an anonymous plan is claimed into an account
  the first time its holder saves or books it, and claiming is first-come
  (a plan that has an owner keeps it). What's still open is the other half —
  whether a *token-only* plan can gain participants without being claimed
  first, which only matters once participants exist at all.

Not scoped or started — flagged here so plan-related work doesn't get built
in a way that has to be torn up. Concretely: assume a plan has **several
people with different permissions** and that **every change is
attributable**, even while only the owner path exists.

## Future concept: on-trip progress tracker (not built yet)

Once a plan is booked and the traveler is actually on the road, they need a
way to see **which stage/day of the plan they're currently on** — a visual
"you are here" indicator layered onto the same itinerary view (map +
day list) used during planning, not a separate screen. Rough shape once we
get to it:

- Reuse the trip map + day list from planning, but in a read-only/progress
  mode tied to a confirmed `Trip` (see `app/Models/Trip.php`).
- Mark today's stage clearly (e.g. a pulsing/highlighted marker) distinct
  from completed and upcoming stages.
- Feed the after-sales features already sketched in `CLAUDE.md` (trip
  checklist, on-trip help, feedback) from the same "current stage" concept.
- Needs a clear model for "confirmed/active trip start date reached" vs.
  "still just a saved plan" to know when to switch from planning view to
  progress view.

This isn't scoped or started — flagging it here so it isn't forgotten, and
so the planning-view data model (stages, dates, accommodation groupings)
gets built with this future use in mind rather than needing a rewrite.

## Backlog

Legend: ✅ done · 🟡 partially done (see note) · ⬜ not started

### 2026-08-12 — room types are part of the listing, not a box under it

- ✅ **A "Room types" tab in the /admin listing form**, between Media and
  Booking system / API — the order the work is actually done in, since the
  rooms are what the connector then sells. They used to be a relation manager,
  which Filament can only render *underneath* the form: the one part of a
  listing that is pure data entry sat outside the place every other part of it
  is edited, below the fold, on a page whose Save button is in the header.
- ✅ **A relationship Repeater, not the relation manager moved.** It cannot be
  moved: Filament renders every table action modal as a `<form>`, and nesting
  one inside the record's own edit `<form>` silently breaks submission in the
  browser — the same trap already recorded on `HasPartnerMessagesTable`. So the
  fields moved out into `App\Filament\Support\BookableUnitSchema` and the two
  surfaces share them; only the frame differs.
- ✅ **The partner panel keeps its relation manager.** Its listing form is
  sections rather than tabs, so there is no strip to move anything into, and a
  lodge editing its own rooms is better served by a table than by a stack of
  open forms.
- ✅ **A count badge on the tab.** With nothing below the form any more, that is
  the only way to see whether a property has its inventory entered without
  opening it — and today almost none do.
- 4 tests in `tests/Feature/Filament/ListingRoomTypesTabTest.php`, the load-
  bearing one being that a departure entered on a new room type still saves:
  that is a relationship repeater nested inside another one, and a silent
  failure there would look exactly like somebody forgetting to press save.

### 2026-08-12 — the room picker reads the lodge's calendar

- ✅ **One price, front and back.** The picker used to quote a room type's base
  rate times the nights. It now prices from the property's own calendar and rate
  plan (`App\Services\Booking\RoomOffers`), so a season, an occupancy rule and a
  tax reach the traveller exactly as they reach the invoice. The payload gained
  `nightly_rates`, `total_payable` and a named `charges` list; `price_per_night`
  is now an average where a stay crosses a season boundary, which is why the
  nights are there to check it against.
- ✅ **Availability stopped lying in one direction.** A booking a lodge took at
  its own desk was invisible to the trip plan, which went on offering the same
  rooms. `RoomAvailability::unitsLeft` now returns the smaller of the calendar's
  free units and what is left after overlapping requests.
- ✅ **A room nobody priced is left out** rather than offered at zero — the
  booking would be refused at the other end anyway.
- ✅ **The last piece: soft holds as real inventory.** A request now takes a
  provisional stay on the calendar while the partner decides, so the room really
  does come off sale — before this, "hold" was a timestamp and the lodge could
  sell the room at its own desk while the traveller waited. Released on decline
  and on expiry, and transitioned into the guest's own stay on confirmation
  rather than becoming a second one.

### Session 1 — 2026-08-04

- ✅ **Summary text truncation.** The AI-generated trip summary paragraph
  above the plan was forcing a lot of scrolling. Now clamped to 2 lines with
  a "weiterlesen…" / "weniger anzeigen" toggle
  (`ItinerarySection.vue` + `kaia-home.css`).
- ✅ **Map marker numbers vs. day-list numbers mismatch.** Root cause: on a
  round trip, day 1 and the last day often share the same coordinates
  (e.g. both Windhoek); Leaflet stacked the last-added arrival marker on
  top, hiding the green "1" start marker underneath. Fixed by giving the
  start marker a higher `zIndexOffset` so it always renders on top
  regardless of add order. Also changed the arrival/end marker color from
  blue to black per spec (`TripMap.vue`).
- ✅ **City names instead of region names.** Two parts:
  - The day list already had a `dayCity()` helper preferring the
    accommodation's city — but the map popup was still showing the raw
    region. Now shows the same city label as the list (region is still
    used internally for routing/driving-time matching — that part of the
    "AI contract" is unchanged and correct).
  - The AI-generated `trip_summary` paragraph was written using formal
    region names ("Otjozondjupa's Waterberg area", "Hardap for the red
    dunes of Sossusvlei"). Added an explicit prompt instruction so the
    summary speaks in real place names (towns/parks/landmarks) instead —
    `day.location` itself is untouched (must stay the exact region for
    map routing).
  - Also fixed: swapping an accommodation via the "⇄ Tauschen" alternatives
    list didn't carry a `city` value, so a swapped-in stay silently fell
    back to showing the region. `alternatives()` and the availability-swap
    path now populate `city` too.
- ✅ **Multi-night stay collapsing.** Consecutive days at the same
  accommodation now render as one visual block: marker + thumbnail +
  "Unterkunft" line + room picker only show on the stay's first day, with a
  combined check-in → check-out date range underneath. Days after the first
  are hidden entirely unless they have their own activity or restaurant, in
  which case just that day's date + activity/restaurant show, no marker.
  (Implemented as `v-show`, not `v-if`, so the underlying day stays
  addressable for drag-and-drop reordering — SortableJS/vuedraggable needs
  a DOM node per array item to keep index mapping correct.)
- ✅ **Drive-time row emphasis.** Now a highlighted rust-tinted band
  (previously just a thin top border) with an editable "Abfahrt" time input
  and a computed "Ankunft ≈ HH:MM" derived from that input + the OSRM leg
  duration. Departure times are local UI state only (not persisted) — there
  is no real scheduled-departure concept yet.
- ✅ **Clickable room photos.** `RoomTypePicker.vue` now shows a clickable
  thumbnail per room option that opens `ImageLightbox` with the property's
  full photo gallery. **Caveat:** there's no per-room-type photo yet (see
  "Known gaps" below) — this reuses the accommodation's own gallery
  (`Listing.gallery`, plumbed through as `ItineraryListingRef.gallery` in
  `ItineraryService.php`) as a stand-in. Good enough to demo, not the real
  thing yet.
- 🟡 **Vehicle photos & alternatives photos/prices.** Vehicle now renders
  as a small card with its photo next to the name/price
  (`.vehicle-card` in `ItinerarySection.vue`). The generic
  `AlternativesPanel` (used for vehicle *and* accommodation/activity/
  restaurant swaps) now shows a thumbnail per alternative too — the
  backend `alternatives()` method was missing `image`/`gallery` entirely
  before this. **Not done yet:** the free-form vehicle type picker
  (Jeep/Mid-range/Camper/…) with a per-day budget field. Deferred — see
  "Known gaps".

### Session 2 — 2026-08-07/08

- ✅ **One chronological day list instead of ERLEBEN/ESSEN boxes.** A day's
  activities and restaurants used to sit in two separate labelled boxes.
  They're now one time-sorted list per day, told apart by icon (📷/🍴) plus
  a small type caption. Added an optional `time` ("HH:MM") to
  `ItineraryListingRef` end-to-end — including Kaia's tool schema
  (`activity_time`/`restaurant_time` in `proposeItineraryTool()`), its
  prompt, and `resolveReferences()` — so generated plans can carry a time
  too, not just manually added entries. Entries without a time keep their
  original order and sink below timed ones, so existing plans render
  unchanged. Replaced the per-box empty-state "+ Add" (which vanished once
  one item existed) with two always-visible add buttons.
- ✅ **Stage card split in two.** A stage's first day used to bundle
  location + dates + accommodation + that day's plan into one card. Now:
  an accommodation card (city, region, date range, stay, room picker),
  then that day's own day-plan card beneath it — so the arrival day joins
  the same card stack as every later day of the stay.
- ✅ **Date bug found while splitting.** `dayEndDateLabel()` preferred
  `date_to` (the *next* day's date) over the day's own `date`, so every
  continuation-day card was showing a date one day ahead of the entries it
  held. Renamed to `dayDateLabel()` and fixed to prefer `date`.
- ✅ **Stage card restructure** (after a prod review): date range moved
  under the city name to the right of the thumbnail; thumbnail became a
  button opening `ImageLightbox`; "Schlafen"/"Stay" renamed to
  "Unterkunft"/"Accommodation"; a "Tagespläne" group heading + one-line
  hint now sits between the accommodation card and the day cards, so each
  day card no longer repeats a label — just its own date, then the add
  buttons, then its entries.
- ✅ **`ItineraryDayPlanCard.vue` extracted.** The arrival day and every
  later day rendered the same ~120-line block twice in two template
  branches, which had already drifted apart once. Both now render one
  component.
- ⚠️ **ESLint is CI-blocking and `vue-tsc`/`npm run build` do not catch
  it.** An `import/order` violation passed both and would have silently
  blocked the deploy (`deploy.yml` only runs after `tests` succeeds). Run
  `npx eslint resources/js` before pushing frontend changes.

### Session 3 — 2026-08-08

Reworking the activity/restaurant rows of a day plan against a reference
design Till supplied — the entries themselves, not the card around them.

- ✅ **`ItineraryEntryRow.vue` extracted.** Activity/restaurant entries no
  longer reuse `ItineraryLineItem` (which is built around a one-line
  "Stay: {value}" sentence). They now have their own component with a
  four-column layout — time · icon · (name + details) · kebab — so entries
  line up down the day no matter how long a name runs, and the kebab always
  sits flush right. `ItineraryLineItem` lost its `icon`/`typeLabel`/`time`
  props again, which only that one caller ever used.
- ✅ **Time is text until you touch it.** Every row used to carry a
  permanently mounted `<input type="time">`, i.e. a boxed form control on
  every line for something most travelers never set. It now renders as
  plain text (`--:--` when unset) and swaps to a focused input — with
  `showPicker()` where supported — on click, or via "Change time" in the
  kebab. Same width in both states, so nothing shifts.
- ✅ **A second line per entry.** Under the name: type, then the estimated
  duration, then (for restaurants) which meal it is, derived from its own
  time rather than stored. Built as a list on purpose — booking facts
  (reference number, confirmation state, instructions) slot in here once
  inquiries are linked to plan entries.
- ✅ **`listings.duration_minutes` added** (migration, both Filament
  panels, listing preview + search payloads, and the plan's own listing
  refs) — for an activity the duration *is* part of what gets booked: a 2h
  quad ride is a different product from a full-day one. Rendered by a
  shared `formatDuration()` (`resources/js/lib/duration.ts`) as
  "~2 h" / "~1 h 30 min", never as decimal hours.
- ✅ **Detail modal reachable two ways** — clicking the entry name (as
  before) and now "Details" in the kebab. `ListingPreviewModal` also shows
  the duration.
- ✅ **Add buttons are one full-width 50/50 row**, taller, instead of two
  small pills floating at the left edge.

### Session 4 — 2026-08-08

Reworking the stage card's accommodation box against a reference design Till
supplied, plus a pass over how prices read across the whole plan.

- ✅ **Missing gap under the stage heading.** Session 2 moved the date range
  into `.day-card-title` and brought `.day-card-title .day-card-sub {
  margin: 0 }` with it, which silently removed the `10px` bottom margin that
  was the only separation between the heading and the box below — the date
  sat flush against the UNTERKUNFT border. The clearance now lives on
  `.day-card-header` itself, which is where it belongs: a stage without dates
  ends on the city name, so hanging the gap off the date line was always
  conditional on content.
- ✅ **`ItineraryStayCard.vue` extracted.** The accommodation used to render
  through `ItineraryLineItem` (built around a one-line "Stay: {value}"
  sentence) with the room picker bolted underneath as a separate row. It's
  now its own component matching the reference: photo, name + price, a
  "room · date range (n nights)" line, a details line, and both actions as
  labelled buttons — "Zimmer ändern"/"Unterkunft ändern" — instead of hidden
  behind the kebab. The stay is the plan's most expensive and most-looked-at
  line; it shouldn't render like a one-line list item.
- ✅ **`short_description` + `rating`/`rating_count` on the plan's listing
  refs**, so the stay card has a "why this one" line to show. Added to all
  five places in `ItineraryService` that build the ref shape, plus the
  `/listings/search` payload behind the swap modal.
- ✅ **One price look across the plan.** Prices were styled per component —
  muted tan on line items and alternatives, ink on the day badge, rust on the
  variant/vehicle totals — so the per-item ones read as incidental grey text.
  All of them now share one rust-dark/semibold rule in `kaia-home.css`; only
  the size varies with the surrounding type.
- ✅ **A price on every line.** `ItineraryLineItem`, `ItineraryEntryRow` and
  the stay card all hid the price entirely when a listing had no
  `price_from` — common on scraped listings, and it reads as "included" or as
  a bug. They now fall back to `itinerary.priceOnRequest`.
- ✅ **Swapped-in listings keep their map marker.** `alternatives()` and
  `ListingSwapModal`'s `select()` both built an `ItineraryListingRef` without
  `lat`/`lng`, so swapping a stay silently dropped it off `TripMap`. Both
  carry coordinates now (`/listings/search` gained `latitude`/`longitude`).
- ⏭️ **Deliberately not built:** the reference's "Etappeninfos" button and the
  pencil next to the city name. Per Till, the stage summary becomes a modal +
  printable PDF later, reached from the burger menu.
- ⚠️ **Two sessions in the same files.** Mid-session a parallel chat was
  editing `ItineraryService`/`kaia-types.ts`/`ListingSwapModal` for
  `duration_minutes`. Check `git status` before starting and before
  committing — `a429213`/`6727d9d` are what happens when that goes unnoticed.

### Session 5 — 2026-08-08

Securing the plan's write access — picked as the top priority because it was
the one open item that was **live and harmful**, not just unbuilt:
`KaiaController::updatePlan` authorized nothing beyond knowing the token, and
the share link *is* the creator's edit token.

- ✅ **Stale writes are rejected instead of silently winning** (`b0ab1f9`,
  `46ce4a2`). A `version` counter on `saved_plans`; the client sends the
  version it loaded and gets a 409 carrying the current server state. Check
  and write are one conditional `UPDATE ... WHERE version = ?`, so two
  simultaneous requests can't both pass it. On a conflict the traveler gets a
  banner and **autosaving stops** — continuing would either spam 409s or
  overwrite the other editor. `version` is not `$fillable`.
  - Gotcha worth remembering: a DB column `default(1)` leaves `$model->version`
    **null in memory** after `create()` until re-read — `savePlan()` was
    returning `"version": null`. Fixed with `protected $attributes`.
- ✅ **The share link is read-only** (`b8e522e`). Second token per plan:
  `token` still edits (so links already out there are unaffected),
  `share_token` reads only and is rejected by `updatePlan`. Share button,
  SaveShareBar and the PDF's printed link all emit the read-only URL; the
  `/trip` page never hands the edit token to a read-only visitor.
  - The concrete leak: `SaveShareBar` built its link from
    `window.location.href`, which on the creator's own `/trip/{token}` page
    *is* the edit URL.
  - Read-only rendering hides every writing affordance and disables the
    autosave outright — otherwise `savePlan()` would mint a viewer their own
    private copy of someone else's plan.
- 10 tests in `tests/Feature/SavedPlanConcurrencyTest.php`. Local runs need
  the Postgres test DB, not `phpunit.xml`'s sqlite:
  `DB_DATABASE=namibway_test DB_CONNECTION=pgsql ... php artisan test`.

### Session 6 — 2026-08-08

Closing the gap between the account line CLAUDE.md *states* and what the server
actually enforced. Picked for the same reason as session 5: these were live,
and two of them were exploitable by anyone holding a link.

- ✅ **Saving to an account is a server rule.** New `POST
  /kaia/plans/{token}/claim` behind `auth` (`KaiaController::claimPlan`) — the
  only path that ever writes `SavedPlan.user_id`. Creating and autosaving a
  plan stay open, so the frictionless chat → plan path is untouched; what an
  anonymous request can no longer do is produce an owned plan.
  - This also fixes a plain bug: `SaveButton` treated *having a token* as
    "saved", and `saveAllVariants()` short-circuited to a no-op when the plan
    was already auto-persisted. So a logged-out traveler who pressed Save, then
    logged in, got a saved-looking bookmark on a row that kept `user_id = null`
    forever and never appeared on their dashboard. The button now tracks real
    ownership (`owned`, reported by `savePlan`/`loadPlan`/the `/trip` page) and
    claims the existing row instead of silently doing nothing. `SaveShareBar`'s
    own "save to account" had the same shape of bug from the other side — it
    called `savePlan()` again and minted a duplicate row whose token diverged
    from the link the traveler may already have shared; it claims now too.
- ✅ **Booking requires an account.** `trips.store`,
  `listings.inquiries.store` and `inquiries.batch.store` are all behind `auth`.
  **Every** entry point asks in the same modal (`SaveLoginModal`, now with an
  `intent` prop for booking-specific copy and an `initialTab` so a "create
  account" button lands on the right tab) — never a redirect to `/login`:
  - the plan's Book button opens it before the guest form,
  - the listing and shortlist inquiry forms stay on screen and open it when
    Send is pressed, then submit themselves once the traveler is in, so
    nothing typed is lost. While logged out the button is `type="button"`, so
    it runs `reportValidity()` by hand first — otherwise an empty form would
    send someone through a login only to come back with errors.
  - the `/trip` page's "keep this plan" banner opens it too, and claims the
    plan on success rather than navigating away.
  - Prefill from the account is a one-shot read (`initialAccount`), never a
    reactive binding: a live one would overwrite a name the traveler had
    already typed the instant they logged in, and send the account's instead.
- ✅ **Only the plan's creator can book it.** `TripController::store` now takes
  a required `plan_token`, resolved against the *edit* token only — a
  read-only share token gets the same 403 as an unknown token, so it can't be
  used to probe for its editable twin. A plan owned by someone else is refused;
  an unowned one is claimed by the booker, since possession of the edit token
  is what "creator" means until an identity is attached.
- ✅ **The one-active-request gate follows the account, not the typed email.**
  Three copies of the same query became `ActiveRequestGate` (matches on
  `user_id` OR `email`). Keying on email alone meant the rule was avoidable by
  typing a second address — which is the one thing it exists to prevent.
- ✅ **`trips.user_id` / `trips.saved_plan_id` / `inquiries.user_id`** (one
  migration). Bookings had no owner at all before, which is why none of the
  above had a column to check. `saved_plan_id` is also the anchor the "booking
  facts on a plan entry" backlog item needs.
- ✅ **Fixed while here:** `GET /trips/{trip}/inquiries` was unauthenticated
  and the id is a plain auto-increment, so counting upwards listed any
  traveler's booked lodges and their confirmation states. Now scoped to the
  trip's owner.
- 18 tests in `tests/Feature/BookingAccountGateTest.php`. Same Postgres caveat
  as session 5 — `phpunit.xml`'s sqlite can't run the migrations.

**Decided, don't undo it:** a single listing inquiry from the Explore path
requires an account too, not just a plan booking. This *is* new friction on a
path that had none, and it was raised and deliberately kept (Till, 2026-08-08).
Two reasons, so nobody "fixes" it later: an inquiry is not a cheap question —
it creates a real `Inquiry`, notifies the partner owner, and occupies the
one-active-request slot, which is precisely what the governance mechanic exists
to protect. And leaving that path anonymous would reopen the email-swap bypass
on it, since an anonymous inquiry has no account to recognise it by.

The middle path that was considered and *not* taken: splitting inquiries into
non-binding questions (no account) and binding booking requests (account). It
needs an inactive `InquiryStatus`, an `InquiryObserver` branch that skips
`ProcessInquiry`, its own partner-side treatment in both Filament panels and
its own mail templates — and it opens an unauthenticated channel to real
business owners. With no partner live yet, that is the wrong first impression
to make. If the friction turns out to cost conversions, shrink the *account
step* instead (create the account from the name and email already typed into
the form, password set later via the confirmation mail) rather than removing
the identity behind the request.

### Session 7 — 2026-08-08

Image thumbnails. Reported symptom: photos in lists load in slowly. Confirmed
— there was **no thumbnail layer at all**. `Controller::resolveMediaUrl()` maps
a stored path to a disk URL and takes no size, so every consumer got the
original; nothing generated derivatives (no Intervention Image, no
`imageResizeTargetWidth` on any Filament `FileUpload`); Google Places photos
are downloaded once at `maxwidth=1200` and that is the *only* size that exists.
Worst case sat in this very feature: the trip plan's day thumbnail renders at
**44 × 44 px** (`kaia-home.css`) and was pulling the full 1200px file.

- ✅ **Resize at the edge, don't store derivatives** (`config/media.php`,
  `App\Support\MediaUrl`, `resources/js/lib/media.ts`). Cloudflare Image
  Transformations serve the same stored original at the requested width, so
  there is no backfill over the existing library and no second copy to keep in
  sync. `format=auto` also buys AVIF/WebP on images we can't shrink further.
- ✅ **The width lives in the component, not the payload.** The same listing
  `image` feeds a 44px day thumbnail, a 48px swap row and a full-bleed hero, so
  the backend can't pick one size. Components call `thumbAttrs(url, width)`,
  which emits `src` + a 1x/2x `srcset`. The PHP twin exists for server-side
  callers and is what the tests pin.
- ✅ **Requested widths snap to a ladder** (64/128/256/400/800/1600).
  Cloudflare bills per *unique* transformation; without this the 44/48/52px
  thumbnails would bill three variants for what reads as one image.
- ✅ **Frontend hygiene alongside it.** `loading="lazy"` was on 4 of 28 `<img>`;
  `decoding="async"` and intrinsic `width`/`height` (layout shift) were absent
  everywhere. Both added across the list/thumbnail renders.
- ✅ **New uploads are capped at 2000px** (`AppServiceProvider`, one
  `FileUpload::configureUsing` for both panels — every upload in the codebase is
  an image). Previously a partner's straight-from-the-phone photo became the one
  and only copy we serve.
- ⚠️ **Ships switched OFF.** `MEDIA_TRANSFORMS_ENABLED` defaults to false,
  because `CLOUDFLARE_R2_URL` still points at `pub-<hash>.r2.dev`, which cannot
  serve `/cdn-cgi/image/`. Until a custom domain is attached to the bucket and
  Transformations are enabled for the zone, every URL passes through untouched.
  Checklist: **DEPLOYMENT.md → "Bild-Thumbnails"**. The one win that lands
  immediately is the Unsplash placeholder heroes, which resize off their own
  query string for free.
- ✅ **Pre-flight command** `namibway:check-media-transforms`. Fetches real
  catalog photos twice — as stored and through `/cdn-cgi/image/` — and compares
  status, type and bytes. Probes the transformed URL *even while the flag is
  off*, so the Cloudflare side can be confirmed before production depends on it.
  Separates the two failure modes a status check would miss: 404 on the variant
  (domain/Transformations missing) vs. 200 at original size (pass-through, not a
  resize).
- ⚠️ **Found while building it: images stranded on a stale origin.**
  `GooglePlacesPhotoFinder` stores `Storage::disk('r2')->url(...)` — an
  *absolute* bucket URL — into `listings.image`. Point `CLOUDFLARE_R2_URL` at a
  custom domain and every existing row still carries the old
  `pub-<hash>.r2.dev` host, so neither `resolveMediaUrl` nor `MediaUrl::thumb`
  touches it and it keeps being served full-size. Since the enrichment pipeline
  is the main photo source, this is probably *most* listing photos, not an edge
  case. The command counts them (the object being in our bucket is what tells
  them apart from a genuinely foreign URL); the fix is still open — see
  "Known gaps".
- Tests: `tests/Feature/Support/MediaUrlTest.php` — pins that a foreign URL is
  never rewritten, that the helper is inert while disabled, and that an
  already-transformed URL isn't nested inside itself.
  `tests/Feature/Commands/CheckMediaTransformsCommandTest.php` — 7 tests
  including both directions of the stale-origin detection.
- ⚠️ **phpstan is CI-blocking and nothing else catches it** — cost one red CI
  here (a `list<string>` return that `Collection::values()->all()` can't prove;
  `array_values()` can). If `composer install` can't fetch phpstan in your
  environment, its release phar downloads fine and runs against the project's
  own `phpstan.neon`:
  `curl -sSL -o /tmp/phpstan.phar https://github.com/phpstan/phpstan/releases/download/2.2.5/phpstan.phar && php /tmp/phpstan.phar analyse -c phpstan.neon`
  (larastan still has to be in `vendor/` for the include to resolve).

### Session 8 — 2026-08-09 (trip plan timeline rows)

Reiseplan layout, from a mockup Till supplied. The old shape showed a stage
card and then, under a "Tagespläne" heading, only the stage's *first* day —
every later day was hidden unless it happened to have an activity or a
restaurant on it. So a three-night stay looked like one day, and days 2–3 had
nowhere to add anything.

- ✅ **Every day of a stage is now its own timeline row.** Day number, date and
  "Ankunft"/"Abreise" moved out of the card onto the rail
  (`.day-rail--day`); the card itself is only the add buttons plus that day's
  entries. `ItineraryDayPlanCard` lost its `dateLabel`/`showDayMenu` props
  along with the header they fed — every day carries its own menu now, at the
  end of the add row.
- ✅ **The stage heading means the stage.** Its date line gained the nights
  count (`stageDateSubLabel`), its price badge sums the whole run rather than
  just the first day (`dayItemsPriceLabel` → `stagePriceLabel`), and its menu
  removes the whole stage (`removeStage`) rather than one day of it — the day
  rows below own single-day removal.
- ✅ **"+ Tag hinzufügen" → "+ Etappe hinzufügen"** on the top/bottom buttons
  and the inline "+" between stages. All three insert an accommodation-less
  day, which is a stage of its own by definition, so the old label described
  the mechanism rather than the result.
- ✅ **Drive-time connectors only render between stages.** Inside one there is
  nothing to drive, and a connector per day broke the block up visually.
- ✅ Each day row folds shut from its rail (`collapsedDays`). Purely a viewing
  state — never persisted, and cleared whenever days are added, removed or
  dragged, since its keys are indices.
- ✅ Fixed alongside: two accommodation-less days in a row used to merge into
  one stage in `isStageStart` while `stageEndIndex` treated each as its own —
  so the second one rendered as a continuation and never offered a way to pick
  a stay. A null identity now always starts its own stage.
- ✅ **"Nacht hinzufügen" on the stay block** (`addNight`) — copies the stage's
  last day, stay and room included, and inserts it into the run so the stage
  grows rather than splitting into two. Removing a night is the day row's own
  menu, so the pair sits on the two objects it belongs to.
- ⚠️ **Every stay control had to be widened to the stage first.** `applySwap`,
  `selectRoom`, `clearRoom` and `removeItem` all wrote a single day while the
  card they hang off spans the whole run — so swapping the lodge on a
  three-night stage produced "one night at the new place, two at the old one",
  and a picked room only applied to the first night despite the card saying
  otherwise. They now write across `stageDayIndices()`. Pre-existing, but
  latent while multi-night stages were mostly invisible; adding nights makes
  them the normal case.
- Deliberately **not** taken from the mockup, per Till: the "Etappeninfos"
  button and the pencil next to the city name.
- Not verifiable in this environment: `composer install` couldn't complete
  (dist downloads fell back to full git clones), so the app itself was never
  booted. The layout was checked against a static harness of the new markup
  plus the real stylesheet at 900px and 390px; eslint, prettier and `vue-tsc`
  are clean.


### Session 9 — 2026-08-09 (real room data in the picker)

Real room data in the plan's room picker. Picked because the picker was the
most visible untruth left in the flagship feature: it invented three tiers
client-side by scaling the property's `price_from`, and rendered them next to
the Book button that session 6 had just put a real account behind.

- ✅ **The picker asks the server instead of making things up.** New
  `GET /listings/{slug}/room-types?check_in&check_out&adults&children` →
  `ListingController::roomTypes`, returning the listing's active `RoomType`
  rows with their real rate, the stay total, remaining units and the room's own
  photos. `RoomTypePicker.vue` fetches on open and re-fetches when the trip's
  dates or party change. Open like the rest of browsing — booking one is what
  needs an account.
- ✅ **An honest empty state.** Most listings hold no room inventory with us
  (scraped listings, partners on their own PMS), so the common answer is an
  empty list — and the picker says the partner will confirm the room rather
  than substituting invented options. `itinerary.roomTypes` and
  `roomPlaceholderNote` are gone from all five locales; loading, failure,
  empty, scarcity and stay-total strings replace them.
- ✅ **`room_types.gallery`** (migration) — a room type had no photo of its
  own, so every option showed the same four pictures of the lodge. Rooms with
  no photo of their own still fall back to the property gallery, but dimmed and
  labelled as a stand-in rather than passed off as the room.
- ✅ **A Filament editor for room types at all.** There was none: `room_types`
  could only be populated by seeder or tinker, which is *why* every production
  listing has none and the picker was inventing. A `RoomTypesRelationManager`
  now hangs off the listing in both panels — the team's and the partner's, since
  partners are the people who actually know their own inventory.
- ✅ **`RoomAvailability`** — the derived-availability query (`total_units`
  minus overlapping active inquiries) was private to `NativeConnector`; the
  picker needs the same answer, and a second copy would be a second thing to
  keep correct. The connector now calls through to it.
- ✅ **The chosen room reaches the booking.** `TripController::store` writes
  `room_selection.code` to `Inquiry.room_type_code`, so the connector actually
  receives the room. Before this the choice was decorative — every option
  produced the same request. First pick wins across a multi-night stay.
- 14 tests in `tests/Feature/RoomTypeAvailabilityTest.php` (overlap maths
  including same-day turnover, party fit, sold-out and inactive rooms, the
  empty answer, past dates, and the booking hand-off).

**Still not real, and deliberately so:** availability is only as true as the
inventory someone entered. Until a partner is live, every listing returns the
empty answer — which is the correct thing to show, but it means the rich path
is untested against real data. The first partner to enter room types is also
the first real test of this.

### Session 10 — 2026-08-09 (city vs. region)

Region names had crept back into the plan as stage headings ("Khomas",
"Otjozondjupa" where "Windhoek", "Otjiwarongo" belong). Session 1 fixed the
display side of this; what came back is the *data* side, so this round closes
it at the source and makes the UI resilient to it anyway.

- ✅ **A day's `location` is now guaranteed to be a city.**
  `ItineraryService::normalizeDayLocations()` resolves it deterministically
  after generation instead of trusting the prompt: the accommodation's own
  city first, then `location` when it already names a real city, then the
  busiest city of the region it named. Unresolvable values (a park name, a
  typo) are left alone rather than blanked. Runs after
  `backfillAccommodation()` (which still matches on the raw model output) and
  before `validateDrivingTimes()` — so days that used to be skipped by the
  driving-time check because their location was a region are now actually
  validated.
- ✅ **The prompt no longer asks for regions.** `routingGuidance()` literally
  told the model that day 1 and the last day "must be the region containing
  Windhoek", contradicting the schema two paragraphs down — that was the
  regression's origin. Both branches now name the start/end *city*, the
  route-template paragraph says a stop's `region` is an area and not a day's
  location, and each branch closes with an explicit granularity reminder.
- ✅ **Every day carries its `region`.** Set by the normalizer, and
  `/kaia/region-coords` now returns a `region` per entry so the UI can label
  a city even on plans saved before this existed, or when the stay's listing
  has no `city_id` (plenty of scraped ones don't). `dayRegion()` in
  `ItinerarySection.vue` and the map popup share one precedence chain, so
  card and popup can't disagree; a region that would just repeat the heading
  is dropped.
- ✅ **Listing detail hero leads with the city** (`ListingDetail.vue`), region
  after it in parentheses and quieter. Both are links into Explore —
  `?city=` and `?region=` respectively, filters that already existed. The
  Explore selects now fold in an incoming filter value that the inspiration
  rows never mention, so arriving from such a link no longer shows a blank
  select over correctly filtered results.
- 4 tests in `tests/Feature/Services/ItineraryServiceDayLocationTest.php`
  pin the normalizer down by making Claude answer with region names.

Follow-up from checking whether vehicles actually have a `city_id` — they do
when seeded, but not when imported:

- ✅ **`ImportProviders` now honours the source's `car_rental` type.** Its type
  map only knew `'vehicle'`, which the NTB directory never emits, so all 253
  rental entries fell through to the name-based guess — misfiling 12 of them
  (e.g. "Discovery Guest House And Car Hire" → accommodation, "Self Drive
  Safaris" → activity), verified by replaying `resolveType()`'s rules over
  `data/scraped/scraped_providers.json`.
- ✅ **Admin can find and fix city-less listings.** A "Has city" ternary filter
  on `/admin/listings` (combine with the type filter for "vehicles without a
  city"), plus an "Assign city" bulk action. The action is **fill-only** — a
  listing that already has a city is skipped and counted in the notification,
  because bulk-writing over existing `city_id` values is precisely the
  `backfill-listing-cities` incident. Correcting a wrong city stays a
  per-record edit.
- ⬜ **Still open: imported listings have no route to a city at all.** NTB rows
  carry neither an address nor coordinates — only a free-text region — so
  `BackfillListingCities` (address-based) can never reach them and every
  import lands on `city_id = null`. Until an admin assigns them, they show
  no city *and* no region anywhere in the product. Deriving a city from the
  free-text region would be guessing, which is what caused the data loss the
  first time; a coordinate-based pass would need the coordinates to exist.

- 2 tests in `tests/Feature/Filament/ListingCityAssignmentTest.php` (filter +
  the fill-only guard) and 1 in `ImportProvidersCommandTest.php`.
- ⚠️ **pint is CI-blocking too, and it also has a phar** — cost one red CI here
  (a double-quoted string with nothing to interpolate, `single_quote`). Same
  trick as the phpstan note above, and this one needs nothing from `vendor/`
  at all, so it works even when `composer install` can't complete:
  `curl -sSL -o /tmp/pint.phar https://github.com/laravel/pint/releases/latest/download/pint.phar && php /tmp/pint.phar --test`
  Run it before pushing when `composer test` isn't available — it catches the
  cheapest class of red CI there is.
  - Worth knowing why `composer install` fails in that environment, so the
    next session doesn't re-litigate it: every one of the 215 packages syncs
    into the cache fine, then a final request trips `Could not authenticate
    against github.com` and nothing is written — no `vendor/autoload.php`, no
    `vendor/bin`. `--prefer-source`, a warm cache, and feeding `COMPOSER_AUTH`
    the ambient `GITHUB_TOKEN` (which is the literal string `proxy-injected`)
    all fail the same way. Plain `curl` to github.com works, which is why the
    pint phar is the way through.
  - **The phpstan phar does *not* rescue that environment**, unlike what the
    session 7 note above implies — it assumes a populated `vendor/` that is
    merely missing phpstan. Here `composer install` leaves `vendor/larastan/`
    an *empty directory*, so `phpstan.neon`'s include of
    `vendor/larastan/larastan/extension.neon` fails before analysis starts,
    and larastan couldn't resolve Illuminate classes without an autoloader
    anyway. phpstan cost a second red CI on this branch for that reason
    (`$city->region?->name` widening a fixed array shape). If you can't run
    `composer test`, pint is coverable locally and phpstan is not — so read
    new phpstan-sensitive code twice: nullable relation access, and return
    types narrower than what `Collection` methods can prove.

### Session 10 — 2026-08-09 (Prod-Incident-Nachwehen)

- **Route-Shape-Validierung in `ItineraryService::generate()`** (`validateRouteShape`):
  Start-/End-Stadt und Tagesanzahl waren reine Prompt-Guidance ohne serverseitige
  Prüfung. Auf Prod kam so ein Windhoek-Rundtrip heraus, der in Otjiwarongo startete
  und eine Windhoek-Etappe *nach* dem Abreisetag anhängte (19.–20. Jan bei Abreise
  18. Jan — ein Tag zu viel im Plan). Jetzt: Tag 1 / letzter Tag müssen in der Region
  der Start-/End-Stadt liegen (Region-Level, wie die ROUTE-Guidance es formuliert),
  und die Tagesanzahl muss exakt `nights + 1` sein. Verstöße laufen über denselben
  Korrektur-Retry wie die Fahrzeit-Validierung; unbekannte Städte werden wie dort
  übersprungen statt geraten (null-skip). Tests in `ItineraryServiceDrivingTimeTest`
  (Fixtures dort mussten als One-Way deklariert werden, sonst reißt die neue Prüfung).
  **Nach dem Retry sind die beiden Prüfungen bewusst unterschiedlich streng** — auf
  Prod gemessen: 4 von 5 Generierungen 502ten allein an der Form, obwohl das Modell
  auf einem anderen Versuch problemlos einen sauberen Plan lieferte. Fahrzeit bleibt
  hart (Sicherheitsfrage), die Form wird laut geloggt und der Plan trotzdem
  ausgeliefert: eine falsche Startstadt ist im Plan sichtbar und editierbar, ein 502
  ist eine Sackgasse — und genau die darf der Reiseplan am wenigsten produzieren.
- **`/kaia/message` 500te komplett — PHP-memory_limit, nicht die AI.** Der Endpoint
  starb nach ~3s mit leerem Body (Fatal, deshalb kein Laravel-Log-Eintrag; nur im
  nginx-error.log sichtbar: *"Allowed memory size of 134217728 bytes exhausted in
  HasAttributes.php"*). Ursache: `candidateListings()` lud **jedes** veröffentlichte
  Listing als volles Eloquent-Modell — inklusive `scrape_data`, dem kompletten
  Scraper-Payload pro Zeile — nur um danach höchstens 20 pro Typ zu behalten. Das
  war latent seit dem ersten Scraper-Import und ist mit der Katalog-Größe schlicht
  über die 128-MB-Grenze gewachsen. Jetzt zwei Abfragen: die Shortlist wird auf einer
  4-Spalten-Projektion (`id, type, price_from, highlights`) entschieden, erst die
  Gewinner werden vollständig geladen. Filterlogik und Reihenfolge unverändert —
  Reihenfolge ist load-bearing, weil `resolveReferences()` nach Namen indiziert
  (letzter gewinnt) und `backfillAccommodation()` das `->first()` Listing je Stadt
  als Ersatzunterkunft nimmt.
- **Bild-Fallback greift jetzt auch bei toten URLs** (`onImageError` in `lib/media.ts`).
  Vorher deckte `item.image ?? '/images/explore/…'` nur ein *fehlendes* Bild ab; ein
  gesetzter, aber nicht mehr erreichbarer Link (umgezogene Betreiber-Website, entferntes
  Foto, geänderter Host) rendert als Alt-Text im leeren Rahmen. Der `@error`-Handler
  tauscht auf das Kategorie-Standbild, löscht dabei `srcset` (sonst gewinnt es über
  `src`) und markiert das Element, damit ein selbst fehlschlagender Fallback nicht
  endlos weiterfeuert. Verdrahtet an allen Katalog-Bildflächen: Explore-Karten +
  Ideen-Reihen + Featured Pick, Reiseplan (Tages-Thumb, Stay-Card, Fahrzeug),
  Swap-/Preview-Modal, Room-Picker, Hero-Chat-Empfehlung, Destinations, Detailseite.
- Kontext desselben Tages, außerhalb dieses Files: `/cdn-cgi/image/`-Wrapping auf
  App-Origin-URLs entfernt (404te alle Destination-/Region-/Fallback-/Städtebilder,
  siehe CLAUDE.md-Incident 2026-08-09) und deploy.sh-Fix für die `touch()`-EPERM-500er
  auf kompilierten Views — inkl. der Regression, die dieser Fix selbst erst auslöste
  (Deploy-User verlor Schreibrecht auf `storage/framework/views`, Build brach ab,
  Produktion hing ~2h im Wartungsmodus).

### Session 11 — 2026-08-09 (the departure day exists now)

From a prod screenshot Till supplied: a 1–4 Jan stage rendered day rows for
1/2/3 Jan only, with "Abreise" on 3 Jan — but 3 Jan is the last *night*;
checkout is the morning of the 4th, and there was nowhere to plan anything
for that morning before driving on. The trip's final day was missing
entirely for the same reason: a `days` entry is a night, so the calendar day
after the last night had no row anywhere.

- ✅ **Every stage ends with a departure-day row.** A new timeline row on the
  checkout date (the last night's `date_to`): hollow rail dot (nights are the
  filled ones), short date, the "Abreise" label that used to sit wrongly on
  the last night, its own fold toggle, and a full `ItineraryDayPlanCard` —
  so an activity/restaurant fits on the departure morning, in the *old*
  stage's city (the swap modal's city filter follows `day.location`). The
  last night's rail label is gone; `dayRailLabel` only says "Ankunft" now.
- ✅ **Storage: `departure_activities`/`departure_restaurants` on the stage's
  last night** (`ItineraryDay`, optional → old saved plans unaffected;
  `normalizeDay` defaults them). Kaia doesn't fill them — they're a manual
  planning surface, like 2nd/3rd activities. `updatePlan`/`savePlan` store
  the document verbatim, so nothing server-side had to change for persistence.
- ✅ **`consolidateDepartureEntries()`** — any edit that changes which day
  ends a stage (drag, reverse, add night, swap merging neighbouring stages)
  moves the entries onto the new stage-end night instead of stranding them
  where no row renders them. Hooked into `renumberDays` (which `addNight`/
  `addDay`/`removeDay`/`removeStage` now call instead of each repeating its
  body) and the accommodation branch of `applySwap`. Scenario-tested
  standalone (addNight, stage-merge, own-stage days, untouched stage ends).
- ✅ **Freshly added empty stages don't get the row** (`hasDepartureRow`):
  a stay must exist first — otherwise "+ Etappe" would spawn two rows before
  anything is picked. Entries already planned always keep their row, even if
  the stay is removed afterwards, so nothing a traveler added can vanish.
- ✅ Departure entries count into the stage price badge and the trip total,
  and the PDF renders them as their own "→ / Departure day" row after the
  stage's last night.
- Not verifiable in this environment (same as session 8): `composer install`
  can't complete, so no booted app and no `npm run build` (wayfinder needs
  artisan). eslint, prettier and `vue-tsc` are clean; the consolidation
  logic ran green against a standalone scenario harness.

### Session 12 — 2026-08-09 (thumbnails, for real)

Thumbnails, for real this time. Session 6 built the half where every component
asks for the width it actually renders at; nothing existed to answer that ask.
The plan was Cloudflare Image Transformations, which turned out to be
unreachable: an R2 custom domain requires the zone to sit in the same Cloudflare
account, and namibway.com's DNS is at OVH. Moving it was rejected as
disproportionate — the same DNS carries the mailbox and the partner-mail poller.

- ✅ **`/thumbs/{width}/{key}`** (`ThumbnailController` + `ThumbnailGenerator`).
  First request resizes the stored original once with GD, writes the WebP copy
  to the same bucket under `thumbs/`, and **redirects** there. Later requests
  only redirect. Nothing to configure: no DNS, no Cloudflare account, no env
  var.
- ✅ **Redirect, not passthrough.** Serving the bytes through Laravel would tie
  up one PHP-FPM worker per image for the length of the transfer — a page with
  20 thumbnails would hold 20 of them. The redirect costs one small round trip,
  which the immutable cache header removes for returning visitors.
- ✅ **Derivatives are a cache, not data.** The original is the only truth, so
  `thumbs/` can be deleted wholesale at any time and simply gets remade. That is
  also how a changed width ladder or quality is applied — there is no migration
  and no backfill, and it is why the R2 cleanup was never actually a blocker.
- ✅ **Guards on a route that generates files from user input:** only widths on
  the configured ladder are served (otherwise `/thumbs/1/…` through
  `/thumbs/9999/…` fills the bucket), no `..`, no thumbnailing a thumbnail, and
  a 25 MP decode ceiling so GD can't repeat the 128 MB exhaustion that took
  `/kaia/message` down the same day. Anything unresizable falls back to the
  original: slower, but the photo still shows.
- ✅ Cloudflare stays wired as an optional upgrade and takes precedence when
  genuinely available — but an origin on `*.r2.dev` or the S3 endpoint is now
  rejected outright, so the flag cannot 404 every photo again (2026-08-09).
- Verified end-to-end in a real browser, not just in tests: the Explore page
  issues **30 requests to `/thumbs/…`** at the right per-slot widths (800 for
  the featured pick, 256 for destination cards) and **zero** to the originals.
  272/272 against Postgres, plus phpstan, pint, eslint, prettier, vue-tsc and a
  production build.
- Tests: `tests/Feature/ThumbnailRouteTest.php` (8 — generation, reuse,
  no-upscale, ladder, traversal, recursion, missing and unreadable originals,
  cache header) and 5 more in `MediaUrlTest` for the URL building.

### Session 13 — 2026-08-09 (the day is the container)

From Till's layout review: the plan read as a stack of separate forms — the
date detached on the rail, add buttons the size of form fields, empty days
as tall as full ones, and borders around borders around borders. The
information hierarchy was rebuilt so the day owns its content; frontend
only, no data-model or server change.

- ✅ **The date lives inside the day card now.** `ItineraryDayPlanCard` grew
  a header line — "9 Jan · ANKUNFT" — that doubles as the fold toggle, so a
  collapsed day still says which day it is (the old rail chevron collapsed
  the whole card away, date included). The day's kebab moved up next to it.
  While a plan has no dates yet the header falls back to "Tag {n}"
  (`dayCardDateLabel`). The rail keeps only the small numbered dot (hollow
  for the departure morning) — orientation, not information.
- ✅ **One box per thing.** The stage block lost its border and became a
  plain heading (city · region · dates · price); the UNTERKUNFT box is the
  stay's one card, the day cards are the days'. Same DOM, restyled —
  `.day-card:not(.day-card--continuation)` is transparent now.
- ✅ **Add actions are lightweight inline text** ("+ Aktivität
  + Restaurant"), not two full-width dashed pills — which is also what makes
  an empty day two short lines tall instead of a large blank container.
- ✅ **"—" + "Zeit festlegen" on timeless entries.** The details line of an
  entry without a time offers a clickable "Zeit festlegen" (opens the same
  native picker); the time column shows "—" instead of "--:--". No time is
  ever invented. Hidden in print alongside the other edit affordances.
- ✅ **Phones actually got the width.** Two real fixes found by rendering,
  not by reading: grid items refused to shrink below min-content
  (`min-width: 0` on the timeline's content column — before this every card
  was a different width, overflowing its track), and three stacked side
  gutters (trip-plan page 24px + section 24px + variant card 22px) left the
  day cards ~200px on a 390px phone — all three slim down under 640px, and
  the stage heading stacks its price/menu vertically there.
- Verified in a real browser against a seeded `SavedPlan` (local Postgres,
  Playwright): desktop 1440 with the sticky map, mobile 390, fold/unfold via
  the new header toggle, and the read-only share view (no add row, no
  set-time, "Noch nichts geplant" on the empty departure day). eslint,
  prettier and vue-tsc clean; `npm run build` unreachable here only because
  the sandbox blocks the bunny.net font fetch.

Follow-up, same day, from Till's second review — the bordered card around
each day still read as a stack of forms on a long stay:

- ✅ **Days are sections of one continuous Tagesplan now, not cards.**
  `ItineraryDayPlanCard`'s root became `.day-plan-section` — no border, no
  background, no rounding. The date line is the section heading with a
  hairline rule under it; whitespace separates the days. Everything else
  (header-as-fold-toggle, add row, merged entry list) is unchanged, so a
  7-night stay reads as one schedule instead of seven boxes.
- ✅ **The price is details-line information.** It moved off the entry's
  title row to the end of the second line ("Aktivität · ~3 h · $ 47") at
  the details size, keeping the shared `.item-price` colour — the name is
  the row's strongest text, and "Preis auf Anfrage" in particular no longer
  competes with it.
- Same verification pass as above (desktop/mobile/fold/read-only rendered,
  eslint + prettier + vue-tsc green).

Third pass, same day — polish only, structure untouched:

- ✅ An unset time renders as an **empty column**, not a "—": the fixed
  46px slot keeps the icons aligned, "Zeit festlegen" on the details line
  is the labelled way in, and the empty button is still the same tap
  target at the spot where the time will appear.
- ✅ The **date grew to 14px** (the day's visual anchor); the rail dots
  went the other way — smaller, sand-toned, lighter weight — so the
  timeline reads as route orientation, not as a second date column.
- ✅ **Tighter vertical rhythm**: section gap 18→10px, entry rows 7→5px
  vertical padding — a 4-day stage fits one desktop viewport with the
  hairline under each date doing the separating, not whitespace.

### Session 14 — 2026-08-10 (a stage has one city again)

Till clicked a stage headed "Otjiwarongo" and the picker opened on
"Windhoek". Two different sources: the heading read the accommodation's
`city`, while the picker — and the map pin, the driving legs, the stage
thumbnail, the swap modal's "same city" default — all read `day.location`.
They agree at generation time (`ItineraryService::normalizeDayLocations`
resolves `location` from the stay's own city) and drift apart the moment
anyone edits, because a lodge swap only writes the accommodation and a city
edit only writes `location`.

- ✅ **`day.location` is the stage's city, full stop.** `dayCity()` prefers
  it and falls back to the stay's city only for a day that has none, so the
  heading is the thing the picker edits. Consequence worth knowing: editing
  the city is now visible — before, picking a new town silently changed
  nothing on screen whenever the stage had a stay.
- ✅ **A stay that moves town takes the stage with it.** `applySwap` writes
  the new lodge's city/region onto every day of the stage. The swap modal
  deliberately lets you leave the current city ("Alle Städte"), so this is
  a normal path, not an edge case.
- ✅ **The region subtitle follows the heading**, instead of preferring the
  stay's region — that is what printed "Otjiwarongo · Otjozondjupa" under a
  Windhoek stage.
- Verified in the browser against a local fixture plan (`CITYMISMATCH`,
  built to hold exactly the reported mismatch): heading and picker both read
  Windhoek; swapping to a lodge in Otjiwarongo moved heading, subtitle and
  picker together; picking Windhoek back in the picker moved the heading
  live. eslint, prettier and vue-tsc clean.

### Session 15 — 2026-08-10 (the stage price says what it means)

Till read the stage badge "€ 17 für 2" next to "1 – 4 Jan 2027 (3 Nächte)"
and could not tell what it was: 17 € for two people for three nights? That
would be absurdly cheap. Two separate faults in one four-word label:

- ✅ **"für 2" is gone.** It appended the party size to a sum that was never
  multiplied by it — `listings.price_from` carries no per-person dimension,
  and nothing in `stageTotal()` touches `adults`/`children_under_13`. The
  count was decoration that read as a guarantee. Party size stays where it
  is true, in `TripMeta`.
- ✅ **The badge now names its period.** Top line is the stage total in the
  same wording as the trip and vehicle totals ("~€ 348 geschätzt"); the
  second line divides it by the stage's nights ("≈€ 116/Nacht"). A
  one-night stage prints only the total — the per-night figure would just
  repeat it.
- ✅ **A stay on request is declared, not averaged.** In the reported case
  the lodge was "Preis auf Anfrage", so the € 17 was activities and meals
  alone — and a per-night figure derived from that reads as a room rate,
  which is exactly the misreading. When the stage's stay has no
  `price_from`, the second line says "ohne Unterkunft" instead.
- The two lines stack right-aligned inside `.day-card-price` (mirroring
  `.variant-price` / `.variant-price-per-day`), so the badge stays as narrow
  as the old one-line version.
- Verified in the browser against a local fixture (`STAGEPRICE1`, three
  stages covering all branches): 3 nights priced → "~€ 160 geschätzt /
  ≈€ 53/Nacht"; 3 nights on request → "~€ 16 geschätzt / ohne Unterkunft";
  1 night priced → total only. At 375px the price block starts at x=235
  with the city title ending at x=225 — no overlap, no page overflow.
  eslint, prettier and vue-tsc clean.

### Session 16 — 2026-08-10 (a price now says what it is per)

Picking up the gap session 15 left behind: the stage badge learned to name
its period, but the numbers going *into* it still didn't. `price_from` was a
bare decimal with no dimension, so an activity row printed "N$ 450" whether
that was per head or for the whole group, and the stage total added a
per-night lodge rate to it as though both meant the same thing.

- ✅ **`listings.price_unit`, a nullable enum** (`App\Enums\PriceUnit`):
  `per_night`, `per_person_per_night`, `per_day`, `per_person_per_day`,
  `per_person`, `per_booking`. Two dimensions in one column — the period it
  repeats over, and whether it is charged per traveler — because those are
  the only combinations that occur and one select is one decision for the
  partner filling it in.
- ✅ **Nothing is backfilled, and nothing is guessed at display time.**
  "Accommodation = per night per room" looks like a safe default and isn't:
  per-person-sharing rates are the norm in this market, so a blanket
  backfill would stamp a confident, frequently wrong claim onto thousands of
  scraped rows that nothing downstream could tell from a confirmed one. Null
  means "not recorded" and is a re-selectable answer in every editor. **The
  entire change is therefore invisible in production until units get
  entered** — every label and every sum is byte-identical for a null unit.
- ✅ **Entered where the price is entered**: both Filament panels and the
  partner self-service editor, each offering only the units that fit the
  listing type (`PriceUnit::forType()` — a lodge can't be quoted "per
  booking", which would break the per-night arithmetic the plan does with a
  stay). The partner panel's price field used to be *labelled* "(per
  night)"; an activity operator entering a per-person rate there was told it
  meant something else entirely.
- ✅ **Carried down every path a listing reaches a plan by** — Kaia's
  itinerary references, the availability fallback, the alternatives list,
  `/listings/search` (what the swap modal writes into the plan), the preview
  endpoint, the homepage payload and the public `/api/v1`. Dropping it on
  any one of them would leave a swapped-in listing unqualified and
  mis-counted even though the catalog knew.
- ✅ **The sums use it.** `itemCost()` multiplies a per-person rate by the
  party from `trip_params`; the vehicle line — the one the plan multiplies
  by trip length itself — no longer multiplies a flat package price by the
  days. An unrecorded unit keeps counting exactly as before (×1, vehicle ×
  days), so the arithmetic only ever changes where someone stated a fact.
- ✅ **Shown next to the number** — stay card, entry row, vehicle line, swap
  list, preview modal, Explore grid, listing detail. One fallback only, and
  a stated one: the stay card still prints "/Nacht" for an unrecorded unit,
  because that is what the stage total has always done with it. i18n in all
  five locales.
- Verified in the browser at 375px against a fixture covering all six units
  plus null: stay card renders "N$ 1.500/Nacht", "…/Nacht p.P.", "…
  pauschal" etc., an unpriced listing still falls back to "Preis auf
  Anfrage" with no unit, and the sums come out 4500/18000 (3 nights,
  4 travelers) for per-night vs per-person-per-night, with the per-booking
  vehicle staying at 900 instead of 2700. No horizontal overflow. eslint,
  prettier and vue-tsc clean. The PHP half could not be run in the session
  itself — `composer install` cannot complete there because
  `codeload.github.com` is 403 through the egress proxy and
  `phpstan/phpstan` is dist-only — so CI executed it first: pint, phpstan
  and 279 tests passed, and the one failure was a wrong assumption in the
  new test rather than in the feature (`/api/v1` is Sanctum-gated, unlike
  the in-app endpoints, so that case needs an `ApiClient` token).

### Session 17 — 2026-08-10 (the traveler picks the vehicle)

Backlog item since session 1: the plan's only notion of a vehicle was a binary
`vehicle_type` ("car" | "camper"), set once during the interview and never
editable afterwards — the "edit trip details" popup didn't even show it. On the
catalog side the match was a free-text search of `highlights` for the literal
string "Camper", which cannot tell a rooftop-tent 4x4 from a motorhome, or a
sedan from a 4x4. The item was explicitly deferred rather than shipping "a
dropdown that doesn't actually change results", so the point of this session was
the results half as much as the picker.

- ✅ **`listings.vehicle_class`, a nullable enum** (`App\Enums\VehicleClass`):
  `sedan`, `suv`, `camper_4x4`, `motorhome`, `minibus`. Orthogonal to the
  existing `vehicle_category`, which answers "does someone else drive it"
  (self-drive vs. guided tour); this answers "what am I driving", which is
  what the traveler actually decides. Entered in both Filament panels, a
  table column and an admin filter, exposed on `/api/v1` (payload + a
  `vehicle_class` query filter) and on the in-app listing payloads.
- ✅ **Nothing backfilled from the old heuristic.** Same doctrine as
  `price_unit`: writing "contains the word Camper → camper_4x4" into the
  column would stamp a guess into the very field that exists to be more
  precise than the guess, and nothing downstream could then tell it from a
  partner-confirmed value. Null means "not recorded".
- ✅ **Two sources of truth on purpose, while the catalog is empty.**
  `matchingVehicles()` matches a recorded class exactly and falls back to the
  old highlights heuristic for rows without one — so today, with no listing
  classified, the shortlist comes out exactly as it did before. The fallback
  is deliberately coarse in the traveler's favour: an unclassified sedan and
  an unclassified 4x4 are indistinguishable, so asking for an SUV keeps both
  rather than returning nothing. An over-broad shortlist is a slightly worse
  suggestion; an empty one is a plan with no vehicle.
- ✅ **A per-day vehicle budget that orders rather than filters.**
  `vehicle_daily_budget` (NAD) sorts the vehicle shortlist affordable →
  unpriced → over budget before the `MAX_CANDIDATES_PER_TYPE` cap, so it
  always decides *which* vehicles Claude may choose from, never whether it
  gets any. The per-day figure mirrors `vehicleTotal()` in
  `ItinerarySection.vue` exactly — a per-person rate carries the party
  multiplier, a flat package price is spread over the trip — so a 7 000
  per-booking week beats a 2 000/day rate at a 1 200 ceiling, which is the
  right answer and the opposite of what the sticker prices suggest.
- ✅ **The class decides the legacy binary.** `vehicle_type` is now derived
  from the class whenever there is one, so "motorhome" + "car" is not a state
  anything downstream has to reconcile. Without a class — every plan made
  before this session — it is left exactly as it was, and the whole feature
  is inert.
- ✅ **`VehiclePicker.vue`**: five tap-target cards (icon, name, one-line
  hint) in an `auto-fit` grid — three across on a phone, five in a row on a
  wide modal, no breakpoint to keep in sync — plus a budget field prefixed
  with the traveler's own currency symbol. The amount is typed in the display
  currency and converted to NAD on the way out (`toNad()`/`fromNad()` in
  `currency.ts`), since NAD is what every price here is stored in. Wired into
  the trip-params popup, with a summary chip ("4x4-Camper, bis € 65/Tag") in
  `TripMeta`.
- ✅ **Kaia can capture it without spending a question.** `vehicle_class` is
  an *optional* property on the interview tool schema, described as
  "only if the traveler was specific" — the interview is capped at a handful
  of questions and this must never cost one. The itinerary prompt now
  explains both the class (overriding the coarse binary, with the explicit
  instruction not to leave the vehicle empty when nothing carries the exact
  class) and the budget (a strong preference, not a hard rule).
- Tests: six new cases in `ItineraryServiceCandidateListingsTest` covering
  exact class matching, the highlights fallback in both directions, the class
  beating a contradicting binary, budget ordering without dropping anything,
  the per-booking spread and the per-person multiplier; plus
  `tests/Feature/Kaia/VehicleTripParamsTest` for the derivation rules,
  the budget's rejection of non-positive input, the regenerate endpoint's
  validation and the params it actually forwards.
- **Not done here:** the picker is only in the trip-params popup, not in the
  chat interview itself, and the budget applies to the vehicle line alone —
  it is not a whole-trip daily spend and doesn't touch `budget_tier`. i18n
  covers en + de; nl/fr/es fall back to English exactly as they already do
  for the rest of the params editor (those locales have no `paramsEditor` or
  `meta` block at all).
- Same session limitation as session 16: eslint, prettier and vue-tsc are
  clean locally, but `composer install` can't complete here
  (`codeload.github.com` is 403 through the egress proxy), so pint, phpstan
  and `artisan test` run first in CI.

### Known gaps / next up

- 🟡 **Price units are recorded but nowhere entered.** The column, the
  editors, the payloads and the arithmetic landed in session 16; no listing
  has a value yet, so every price still prints exactly as it did before.
  Same shape as the room types: this is content work now, not code. Two code
  follow-ups were deliberately left out — the budget tiers
  (`ItineraryService::budgetTier`, `Listing::scopeFilterBy`) still band on
  the raw `price_from`, so a per-person rate lands a tier too low, and
  Kaia's catalog isn't told the unit either.

- ⬜ **Booking facts on a plan entry.** The entry's detail line and its
  modal are both structured to take them, but nothing links an `Inquiry`
  to the plan entry it came from — so there is no booking reference,
  confirmation state, or partner instruction to show yet. This is the
  natural next step after staged confirmations (see CLAUDE.md's "core
  product mechanic"), and it wants the collaborative data model to land
  first so "who booked this" has an answer.

- ⬜ **Real city/destination photo galleries.** The stage thumbnail opens a
  slider, but there is no gallery column for places — `destinations` and
  `cities` each hold a single `image` (and no city row has one set), so the
  lightbox is mostly filled with the *accommodation's* photos. Needs a
  `gallery` column on both tables, Filament fields, and sourced photos.
  Full plan, including which 16 places actually need photos and the
  verified Unsplash sourcing method: **`CITY_GALLERIES.md`**.
- ✅ **Real per-room-type photos and availability** — done in session 9. What
  remains is not code: no listing has room types entered yet, so the picker
  shows its empty state everywhere in production. Filling that in is content
  work (and, for partner-owned listings, the partner's own job now that they
  have the editor).
- 🟡 **Vehicle type + daily budget picker** — built in session 17: a real
  `VehicleClass` enum on the listing, a five-option picker plus a per-day
  budget in the trip params, and both actually narrowing and ordering the
  vehicle shortlist. What remains is not code: **no listing has
  `vehicle_class` set yet**, so the class currently resolves through the old
  highlights fallback everywhere in production and only starts biting as the
  catalog gets filled in. Same content-work shape as room types and price
  units. Open questions deliberately left: the picker lives in the
  trip-params popup only (the chat interview asks nothing extra), and the
  budget covers the vehicle line, not a whole-trip daily spend.
- 🟡 **Collaborative trip plan** (read-only vs. write sharing, co-planning,
  comments with follow-ups, change log) — see the dedicated section above.
  The live-relevant halves are done: the share link is read-only and a stale
  write is rejected instead of silently clobbering (session 5), and a plan now
  has a real owner that saving and booking are checked against (session 6).
  Still open: participants as first-class rows, per-person write grants,
  comments, and change attribution — i.e. everything that needs a person
  attached to *each change* rather than a single owner per plan.
- ⬜ **Images stranded on an old media origin** — only matters if
  `CLOUDFLARE_R2_URL` ever changes. Google Places photos store an absolute
  bucket URL at download time, so such a change leaves those rows pointing at
  the old host. Since session 11 this no longer blocks thumbnails (the route
  keys off the bucket path, and `photos:audit-r2` matches on filename), so it
  is a tidiness item, not a correctness one.
- ⬜ **On-trip progress tracker** — see the dedicated section above.
- ✅ ~~Removing a single day from inside a collapsed multi-night stay isn't
  possible from the UI anymore~~ — fixed in session 8: every day of a stage
  has its own row and its own menu, so a stay can be shortened a night at a
  time again without going through the params editor.
- ✅ ~~Adding a night to an existing stage has no direct control~~ — fixed in
  session 8: "Nacht hinzufügen" on the stay block copies the stage's last day
  (same place, same stay, same room, empty day plan) and inserts it into the
  run, so the stage grows instead of splitting.
- ⬜ Everything else from the original prototype
  (`namibia_travel_prototype.html`) not yet ported: the booking-request
  queue animation (one-active-request-at-a-time), after-sales cards,
  the Explore browsing grid's expandable filters.

## How we're working through this

Small, reviewable steps, one session at a time, each one committed and
described here before moving to the next. If you're picking this up in a
new session: read the "Known gaps" list first, pick the next item (or ask
what's most urgent), and add a new dated entry under Backlog when done.

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
  panel (currently placeholder room data, see below).
- `resources/js/components/home/TripMeta.vue`, `TripParamsEditModal.vue` —
  trip-level params display/edit (regenerates the plan via Kaia).
- `app/Services/Kaia/ItineraryService.php` — builds/resolves the plan
  server-side: Claude tool-use schema, listing resolution, alternatives,
  routing/driving-distance guidance, availability pre-checks.
- `app/Models/Listing.php`, `RoomType.php`, `SavedPlan.php` — the backing
  data. `RoomType` exists (per-listing room/unit types with real
  availability logic for the Native connector) but is **not yet wired** to
  the frontend's room picker — see "Known gaps" below.

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

That resolves who needs an account, but it isn't implemented as stated:

- Saving is gated in the frontend only (`SaveButton` emits `need-auth` when
  `isLoggedIn === false`); `SavedPlanController::store` happily accepts an
  anonymous save with a null `user_id`, which is what the token auto-persist
  path (`runPersist`) relies on. So "saving needs an account" is a UI rule,
  not a server rule — fine while the two paths are one and the same, but it
  needs a real distinction once plans have owners.
- Booking requires neither login nor plan ownership today:
  `ListingController::storeInquiry` / `storeBatchInquiry` and
  `TripController::store` are unauthenticated, and the active-request gate
  keys on the submitted email address. There is no "creator" concept being
  checked anywhere, so "only the creator can book" has no enforcement point
  yet — `SavedPlan.user_id` is the obvious anchor, but nothing reads it.

Still open:

- Live/simultaneous editing, or is "refresh to see changes" acceptable for
  v1? Live collaboration is a much bigger build (broadcasting, presence).
- Beyond booking, what else stays creator-only — revoking access, deleting
  the plan, changing trip params that invalidate others' work?
- What happens to a plan created anonymously (token only) once someone
  wants to collaborate on it: claim it into the creating account first, or
  can a token-only plan gain participants?

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

### Known gaps / next up

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
- ⬜ **Real per-room-type photos.** `RoomType` (the real bookable
  room/unit model, used by the Native connector's availability logic) has
  no `gallery`/image field, and the frontend's `RoomTypePicker.vue` room
  options are still 100% synthetic (scaled off the accommodation's
  `price_from`, not real rates/availability). Wiring this up for real needs:
  a `gallery` column + Filament admin field on `RoomType`, and switching
  `RoomTypePicker` from its client-side placeholder generator to an actual
  per-stay availability call.
- ⬜ **Vehicle type + daily budget picker.** The trip already carries a
  binary `vehicle_type` ("car" | "camper") in `TripParams`. The ask is a
  richer selector (Jeep, Mid-range, Camper, etc.) plus a budget-per-day
  field, explicitly so vehicles can later be searched/filtered by type —
  which means it also needs real vehicle categorization on the `Listing`
  side (today "camper" detection is a `highlights` string match, nothing
  richer). Scoped out of session 1 rather than shipping a dropdown that
  doesn't actually change results.
- 🟡 **Collaborative trip plan** (read-only vs. write sharing, co-planning,
  comments with follow-ups, change log) — see the dedicated section above.
  The two live-relevant halves are now done (session 5): the share link is
  read-only, and a stale write is rejected instead of silently clobbering.
  Still open: participants/identities, per-person write grants, comments,
  and change attribution — i.e. everything that needs a person attached to
  a change rather than just a token.
- ⬜ **On-trip progress tracker** — see the dedicated section above.
- ⬜ Removing a single day from inside a collapsed multi-night stay isn't
  possible from the UI anymore (only the stay's first day and any day with
  its own activity/restaurant are shown/removable) — shortening a stay
  today means editing trip dates via the params editor instead. Worth a
  small follow-up (e.g. a "−1 night" control on the stay block) if this
  turns out to matter in practice.
- ⬜ Everything else from the original prototype
  (`namibia_travel_prototype.html`) not yet ported: the booking-request
  queue animation (one-active-request-at-a-time), after-sales cards,
  the Explore browsing grid's expandable filters.

## How we're working through this

Small, reviewable steps, one session at a time, each one committed and
described here before moving to the next. If you're picking this up in a
new session: read the "Known gaps" list first, pick the next item (or ask
what's most urgent), and add a new dated entry under Backlog when done.

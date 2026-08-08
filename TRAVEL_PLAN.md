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

## Future concept: collaborative trip plan (not built yet)

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

- `SavedPlan` is a single row with a `token`, `plan_json`, and a `user_id`/
  `session_id` that are **recorded but never checked**.
  `KaiaController::updatePlan` accepts a PATCH from anyone who has the
  token, so the current share link is effectively full write access, and
  `loadPlan` likewise gates on nothing. There is no read-only mode to give
  someone today.
- Whole-document `plan_json` overwrites mean two people editing at once
  silently clobber each other (last write wins, no merge, no conflict
  signal). Concurrent editing needs at least item-level writes or a version
  check before this is safe to hand to several people.
- Nothing is attributable: a change leaves no trace of who made it, so the
  log has nowhere to come from yet.

Open questions to settle before building:

- Do participants need accounts, or is a named-but-anonymous "who are you?"
  prompt on opening a write link enough? (Today's flow deliberately avoids
  forcing login — see "Drop login requirement from trip-plan sharing".)
- Live/simultaneous editing, or is "refresh to see changes" acceptable for
  v1? Live collaboration is a much bigger build (broadcasting, presence).
- Does the owner keep special rights (revoke access, delete the plan,
  final say on bookings)? Booking a shared plan especially needs one clear
  responsible person — the request-governance rules in `CLAUDE.md` are
  written around a single traveler.

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

### Known gaps / next up

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
- ⬜ **Collaborative trip plan** (read-only vs. write sharing, co-planning,
  comments with follow-ups, change log) — see the dedicated section above.
  Note the security side of this is already live-relevant, not just future
  work: today's share token grants write access to anyone who has the link.
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

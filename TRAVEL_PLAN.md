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
  data. `BookableUnit` holds per-listing room/unit types; availability is derived
  by `app/Services/Booking/RoomAvailability.php`, which both the Native
  connector and the plan's room picker go through. Edited via the
  `RoomTypesRelationManager` on the listing in the admin *and* partner panels.
  Since 2026-08-12 the picker's *prices* come from the property's own rate plan
  and calendar too (`app/Services/Booking/RoomOffers.php`), taxes included — so a
  season a lodge priced reaches the traveller, and what the plan quotes is what
  the folio will later say the stay owes.

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

## Future concept: places, not just businesses (proposed 2026-08-23, not started)

Raised by the co-founder as positioning — **NamibWay is the ecosystem, Kaia is
the intelligence layer, and together that is "Travel Intelligence for
Namibia"**: Kaia should eventually connect travelers to what makes the country
itself — attractions, history, heritage, geology, wildlife, culture, hidden
places — and link those to the businesses around them. The two questions it
should be able to answer: *"I'm in Tsumeb for two days, what gives me a real
understanding of this part of Namibia?"* and *"I'm driving Windhoek → Etosha,
what can I see along the way?"*

Framed correctly as **not a Kaia change**. It is a missing entity — but a
smaller one than it looked before the two entries below (2026-08-18 and
2026-08-19) landed, which is most of what this section is for.

### What the geography already gives us

- **A tourism area is a place now.** `PlaceType` covers `national_park`,
  `nature_reserve` and `landmark`, and 22 of them are seeded — Etosha,
  Sossusvlei, Twyfelfontein, Spitzkoppe, Kolmanskop, Cape Cross, Fish River
  Canyon, Waterberg, Skeleton Coast, Sandwich Harbour and the rest. Several are
  literally on the co-founder's list. They are in the driving matrix and Kaia
  resolves their short names (`ItineraryService::canonicalCity()` → the alias
  index, "Etosha" → "Etosha National Park").
- **`Destination` is the area above them**, not just a homepage card:
  `cities.destination_id`, it filters listings, it is what the traveler reads,
  and since #199 it reaches the itinerary prompt as `area`
  (`Listing::getAreaAttribute()` → `toAiCatalog()`).

So the earlier framing of this section — "there is nowhere in the database that
Hoba Meteorite belongs, and Kaia only ever sees businesses" — is no longer
right and has been corrected here. Coordinates, a place taxonomy, an area above
it and driving times between them all exist.

### What is actually still missing

Kaia knows the **names and coordinates** of places. She knows nothing **about**
them, and nothing that isn't a container for a listing. The dividing line is
already written down in `PlaceType::inDrivingMatrix()`: a park or reserve is in
that table *precisely because something bookable stands on it*. The Hoba
Meteorite, Lake Otjikoto, the dinosaur footprints at Otjihaenamaparero, a hot
spring, a rock-art site with no lodge on it — nothing is sold there, so nothing
puts them in `cities`, and nothing should.

That is the gap: **a thing you go and look at**, as opposed to a place you are
in.

### Shape if we do it

- A separate **point-of-interest** table, not another `PlaceType` and not a
  `Listing`. #198 deliberately widened the one place taxonomy rather than
  adding a second location entity, and that was right for *places* — but a POI
  is a different noun with different attributes: a visit duration (the shape
  `listings.duration_minutes` already has), access facts (4x4 only? permit?
  entry fee? best season and time of day?), and no listings filed under it.
  Making it a `PlaceType` would put a meteorite in the city-to-city driving
  matrix and offer it as a day location and an Explore filter value. Making it
  a `Listing` would leak it into Explore, the room picker, `/availability` and
  the inquiry flow. It belongs beside both — same reasoning that keeps
  `menu_items` out of `bookable_units`.
- **It hangs off the existing geography rather than duplicating it**: nullable
  `city_id` (the place it sits in or nearest to) plus its own coordinates,
  which is what makes it routable. Several entries are *both* — Twyfelfontein,
  Sossusvlei and Kolmanskop are already `cities` rows because lodges are filed
  there, and are also things you drive to and walk around. In those cases the
  place row stays the container and the POI row carries the visit facts; they
  point at each other rather than one being deleted in favour of the other.
- **Coordinates do the linking to businesses**, not a curated join table — what
  is near this POI, what lies along this route — so the corpus pays off with
  every partner we onboard rather than only with editorial work.
  `city_driving_hours` + OSRM + lat/lng already carry that. Curated relations
  only as an override on top.
- **"Local businesses, communities, guides" are not POIs.** A business with no
  listing is already expressible: an `Inquiry` names a listing *or* a partner,
  and the website builder sells to exactly those trades. That is a `Partner`
  (+ optionally a `Site`). Keeping "a place", "a thing to see" and "a business"
  as three separate nouns is what keeps the booking core out of the content
  question.

### Two cautions worth writing down before anyone starts

- **Cost and latency live in exactly this dimension.** The catalog is capped at
  `MAX_CANDIDATES_PER_TYPE` on purpose — loading every published listing as a
  model took `/kaia/message` down with an OOM on 2026-08-09. A POI corpus with
  editorial text grows the prompt the same way, and #199 has already added
  `area` to every catalog entry. So POIs must be pre-filtered geographically in
  SQL before the model sees anything, and **long-form text must never enter the
  itinerary prompt**: that call needs a name, a coordinate, a category and one
  line. The deep content is what a detail page renders and what a separate
  answer-a-question call retrieves.
- **The content is the expensive part, not the code.** Encyclopedic facts about
  the Hoba meteorite are free — the model already has them, so they
  differentiate nothing. What cannot be copied is operational truth (which
  track, 4x4 or not, permit, entry fee, best time of day), the proximity link
  to real bookable inventory, and the fact that the answer becomes a day in a
  plan with one tap. Weight the corpus toward that. Rights apply as everywhere
  else: text and photos taken from a third-party directory stay
  `ContentSource::directory` and are not publishable, so this needs its own
  photography plan.

### And one of the two questions is a second conversational mode

"I'm in Tsumeb for two days" is not the itinerary generator. Kaia today only
plans a trip from scratch (interview → params → full plan); there is no path
for answering a question about a place. That is real work — and it is the same
mode as the on-trip tracker in the section above. "What's along the road to
Etosha" is the nearer half: a corridor query over `RouteTemplate` stops and
coordinates, close to what the plan already computes.

### Recommended first step

Smaller than it was a week ago, because the geography landed in the meantime.
Add the POI entity, fill 30–50 entries anchored to places that already exist
(the 22 tourism areas plus the towns on our `RouteTemplate`s), and ship **one**
visible thing — attractions along the route in the existing trip plan, a map
pin plus a line in the day. If travelers engage with it, the corpus is worth
funding; if not, it cost a week rather than a quarter.

Naming: "Travel Intelligence for Namibia" is positioning, not schema. Keep
Namibia out of table names — a POI table hanging off `City`/`Destination`
travels to the next country by construction (see CLAUDE.md → "Brand &
expansion").

## Backlog

Legend: ✅ done · 🟡 partially done (see note) · ⬜ not started

### 2026-08-24 — what has to be in the car before the drive starts

The line under a driving time learned this morning to say what is worth
*seeing* between two stages. The other half of a long Namibian leg is what you
need to have with you on it, and it is the half with consequences: the last
pump before 250 km of gravel, the last supermarket before three self-catering
nights at a camp with no shop. That line now carries both — **Stock up**,
under **On the way**, in the same box.

**It is a different noun and it has its own table** (`supply_points`,
`App\Models\SupplyPoint`). Filing it under `attractions` was considered and
rejected in three sentences: nobody *goes* to a filling station, so the
measure of one is not whether it is worth a detour but whether it is the last
one; the columns that decide whether it is any use — opening hours, which pump
— are meaningless on a meteorite, and `visit_minutes`, `entry_fee`,
`requires_permit` and a gallery are meaningless here; and an attraction stays
true for a decade while a filling station closes, changes hands or runs dry,
which is what `verified_at` exists to admit. Same reasoning that keeps
`menu_items` out of `bookable_units`.

**The rule is a relation to the road ahead, not a position on the leg** —
which is why `App\Services\Routing\SupplyStopFinder` takes the whole route
and could not answer this a leg at a time. Every supply point near the route
gets a position along it; per service, the gap after each one is the distance
to the next place with that service, or to the end of the plan. A stop is
named when that gap is at least 160 km for fuel or 225 km for food.

That is what makes it self-limiting, and it is why the interesting question
was never "where are the filling stations". Windhoek has thirty forecourts;
twenty-nine of them have another one a kilometre later, so not one is named.
What gets named is the last one before the empty stretch — and on the classic
loop that is exactly one chip per long leg: Rehoboth as the last supermarket
for 393 km on the way to Sossusvlei, Solitaire as the last fuel for 170 km on
the way to the coast, Opuwo as the last of both before the Kaokoveld.

**Groceries carry a second trigger, because distance is not what makes a
supermarket matter.** A self-catering stay is. If one lies between a grocery
stop and the next, that stop is named however short the drive — this is "the
last supermarket before a self-catering camp", and it is why the endpoint
takes the stay each leg arrives at. The browser sends a slug and nothing else;
whether that stay is self-catering is `Listing::isSelfCatering()`'s answer to
give, from the chosen amenities where a property has entered them and from its
own free text where it has not — the same two-source shape as
`matchingVehicles()`, erring towards true, because a chip suggesting a shop
nobody needed costs a glance and the miss costs them dinner. A camp with a
shop of its own never triggers it.

Where it deliberately differs from the attraction finder, each for its own
reason:

- **Nothing is excluded for being at a stage.** The pumps in the town you are
  sleeping in are not "already part of that stage" — they are the reason the
  gap there is zero. A supply point beside a shared stage matches *both* the
  leg that arrives and the leg that leaves, which is load-bearing rather than
  a duplicate: the arrival closes the previous gap, the departure is what gets
  named when a long empty leg follows. So "fill up in Otjiwarongo" appears
  above the drive that needs it, not above the drive that ended there.
- **No minimum leg length.** A stop worth naming is worth naming on a 30 km
  transfer; the road after it does not care how short this leg was.
- **A stop may be named twice on a round trip.** You need fuel in both
  directions. Seeing something twice is pointless; filling up twice is the
  idea.
- **The same corridor, not a tighter one.** The first instinct was to narrow
  it — nobody detours 40 km for diesel — and that is the wrong way round: a
  stop is only ever named when it is the last chance, and 40 km is exactly
  what somebody would drive for the last chance.

**The thresholds are straight-line kilometres and deliberately below the road
distances they stand for** (~200 km of driving for fuel, ~250 for food). A
road is longer than the line it follows, and in the Namib a good deal longer —
the C14 from Sesriem to the coast runs some 350 km over a 240 km line. That
crossing is the case the fuel number was settled on: at 180 km the plan said
nothing about Solitaire, which is the one place on that road everybody stops
for fuel. Nothing here is ever quoted as a driving distance; it is shown as
`≈` and it is a lower bound, which is the safe direction for a number somebody
plans a tank around.

**Opening hours are OpenStreetMap `opening_hours` syntax**, verbatim, in one
column — the standard every source these rows will be filled from already
speaks, so an import stays a copy rather than a translation.
`App\Support\OpeningHours` parses a documented *subset* (weekday selectors and
clock ranges, `24/7`, `off`) and **refuses everything else rather than
half-reading it**, because a traveller drives on what this says; the admin
field validates against exactly that parser, so what cannot be read cannot be
saved. Days come out as keys and the browser names them from its own locale,
so a German reader is not shown "Mo-Fr" because an English-speaking content
manager typed it.

**Content shipped with it**: 57 towns and settlements down the roads people
actually drive, seeded in a migration and named after the place rather than
the forecourt — "there is fuel in Kamanjab" is the fact the rule needs, and
which of the two pumps it is at is not. Each takes its coordinates from the
town or place it is filed under, so nothing here is a coordinate typed from
memory (the two Etosha camps excepted, being in a park rather than a town);
`namibway:backfill-supply-point-coordinates` is the second half of that, for
rows whose town had not been geocoded when the migration ran. Coverage matters
more than detail here and in a specific direction: **a missing row does not
make the plan quieter, it makes a gap look longer than it is** — which errs
the safe way, and is why the copy never claims there is nothing ahead, only
how far it is to the next one we know of.

- `GET /supply-stops/along-route`, `App\Http\Controllers\SupplyStopController` —
  its own endpoint rather than another key on the attraction one: the two fail
  independently, and only this one needs the stay each leg arrives at.
- `App\Services\Routing\RoutePointResolver` — the stage-name lookup pulled out
  of `RouteStopFinder` now that a second finder needs it. Two answers to "where
  is Etosha?" would eventually differ.
- The chip opens *in place* rather than in a modal: four facts and no
  photograph, so a panel would be a worse answer than a line under the chip
  that was tapped. `verified_at` shows there as a quiet caveat and nowhere
  else — a warning on every chip is one nobody reads on the day it matters.
- `/admin` → Content → **Supply points**, and `SupplyService` is the only
  classification the table has: a `type` column beside a service list asks the
  same question twice and lets the two answers disagree.
- Tests: `tests/Feature/Content/SupplyStopsTest.php` for the rule one part at a
  time, plus the seed being filed against real towns; `tests/Unit/Support/OpeningHoursTest.php`
  for what the parser reads and what it refuses.

**Not done, deliberately:** the trip PDF does not carry the line (neither does
"On the way"); `atm`, `water`, `gas` and `tyre_repair` are recorded but drive
no rule, and are shown only in a stop's detail; nothing is checked against the
clock, so a stop is never suppressed for being closed when the traveller would
pass it — the hours are shown and the reading is theirs. And every seeded row
is unverified, which the admin table says in as many words: that is content
work now, and the cheapest kind, since one phone call is one row.

### 2026-08-24 — the phone gets one screen at a time

From a phone screenshot of production: the landing screen showed the headline
and the top half of the chat panel, and the first thing a traveller had to do
was scroll — before they could type a word into the thing the whole product is
built around. Below that, once a plan existed, the chat stayed on the page
above it, so coming back to look at the trip meant scrolling past the whole
conversation every time.

The rule the mobile Kaia tab now follows is **one screen, one thing**:

- **Arrival is the hero and the chat, and it fits.** The teaser is smaller on a
  phone (30px, 25px on a screen under 700px tall), and — the part that actually
  makes it a guarantee rather than a lucky font size — `#kaia-hero` is now a
  flex column capped at the space between the fixed header and the fixed tab
  bar, with `min-height: 0` all the way down to `.chat-log`. Whatever the
  headline does not use goes to the panel, so on a short phone the *log*
  scrolls and the page does not. Verified at 375×667, 390×806, 430×932 and an
  820×1180 tablet: no page scroll in any of them, input row above the tab bar
  in all of them.
- **Tapping into the chat fills the screen** — that was already true, and it
  now keeps the tab bar instead of hiding it, which is what the request asked
  for ("zwischen Header und Footer"). The bar is hidden again only while the
  keyboard is up, where it is behind the keyboard anyway: `HeroChat` sets
  `html.keyboard-open` from `visualViewport`, and that zeroes
  `--mobile-nav-space`, the single declaration of how much room that bar takes.
- **Once a plan exists, the plan is the tab.** The hero/chat is hidden, and the
  plan carries a "Back to Kaia" pill at its very top. The open question in the
  request — how do you get back to the plan from the chat? — has two answers:
  the chat's own back button, which now reads "Back to your plan" when there is
  one, and tapping the Kaia tab again, which lands on the tab's main view the
  way a tab bar is expected to.

Two things fell out of hiding the hero and are worth knowing: the plan needed
its own clearance under the fixed header, and the header had to stop being
transparent — the overlay state is see-through *over a photograph*, and over
the plan's paper it is a white logo on cream.

**The map button is drawn now**, not the 🗺️ emoji: a tan folded map on the
night-blue circle, the same tan-on-blue as the compass in the tab bar. An emoji
is rendered by the OS, so that control was a different picture on every phone
and in a palette nothing else on the page uses.

Desktop is untouched: every rule above is inside the touch/`data-mobile-section`
block, and the only sitewide change is the type scale under 640px.

### 2026-08-24 — what you drive past on the way

Windhoek to Waterberg is two hours; Windhoek to Etosha is most of a morning.
Those hours are the shape of a Namibian trip, and until now the plan said
nothing whatever about them beyond how long they take. The things beside those
roads are already in `attractions` — the meteorite outside Grootfontein, the
sinkhole at Otjikoto, the dinosaur tracks at Kalkfeld — and a traveller drove
past every one of them without knowing.

So the drive-time box now carries a second line: **On the way**, and up to three
names, with a `+N` that opens the rest. A name opens the same detail modal the
activity picker uses, because an attraction has no listing page of its own. On a
leg with nothing to report the box looks exactly as it did.

**The rule for "on the way" is detour, not distance from a line.** A
perpendicular distance says a site 20 km to the side is equally worth stopping
for whether it sits at the midpoint or 5 km short of the destination, which is
not what a traveller means. `App\Services\Routing\RouteStopFinder` computes
`d(from → it) + d(it → to) - d(from → to)` — what going there actually costs —
and offers it when that is under 40 km and under a quarter of the leg. Straight
lines, not roads: this runs on a live plan render, and paying for a routing call
per candidate to sharpen a filter whose output is three chips would be absurd.
The number is shown as `+≈12 km` and never as a driving distance.

Four exclusions, each of them a thing that looked right and was not:

- **Anything at either end.** Heroes' Acre is a Windhoek afternoon, not a stop
  on the road out of Windhoek. Within 25 km of either stage and it belongs to
  the stage.
- **Anything filed under a stage anywhere on the route.** Coordinates are not
  enough on their own — a park centroid can be 40 km from the waterhole the
  traveller is spending two nights at, far enough to pass the distance test and
  still be somewhere they are already going. So `place_id` is checked against
  every stage of the whole route, which is why the endpoint takes all the legs
  at once rather than one at a time.
- **A leg under 50 km.** Nobody is looking for somewhere to stretch their legs
  on a transfer, and a chip row there reads as clutter rather than as a find.
- **The same site twice.** On a round trip the two legs run the same road; the
  stop is offered on the way out, where a whole day is still ahead.

Anything already in the plan drops out client-side, so adding a stop to a day
removes it from the road above that day rather than showing it in both places.

**Nothing about this is persisted.** The plan document says where the traveller
sleeps; what happens to stand beside the road between two of those places is
derived, and derived from data that gets better every time somebody adds a row.
One request covers the whole route (`GET /attractions/along-route`), the map is
what triggers it — it is already the thing that knows the route — and a fetch
that comes back for a route the traveller has since edited is dropped rather
than rendered.

The catalog is the limit now, not the code: 47 attractions across a country this
size means many legs have nothing to say, and the user's own example — Windhoek
to Waterberg — is one of them. That is content work, and it is the cheapest
content work in the product: one row is one thing a traveller stops for.

**Next, and noted rather than built:** the same line should eventually carry
*provisioning* stops — fuel and a supermarket — for the leg where the next
250 km have neither. That is not an attraction and does not belong in that
table; it is a different noun with opening hours and a fuel type, and the
interesting question is not where the filling stations are but which leg makes
one worth naming. *Built later the same day — see the entry above.*

### 2026-08-24 — a day entry is a night, and the departure day is not one

From a prod screenshot: a trip booked as **1–18 January 2027, 17 nights** ended
on a Windhoek stage reading **"17 – 19 Jan 2027 (2 Nächte)"**, with day rows for
18 Jan and 19 Jan under it. The traveler is home on the 18th. The plan invented
a night, and then a checkout after it.

The cause is one line of prompt, not the timeline code. A `days` entry is a
**night** — that is the model the whole plan is built on: `stageNights()` counts
the days in a stage, `addNight()` inserts a day, `applyDates()` gives day *i*
the range start+i → start+i+1, and what happens on the checkout morning rides on
the last night as `departure_activities` (session 11, and the PDF says so in as
many words). Kaia, though, was asked for `nights + 1` entries, one per calendar
day the traveler is in the country, departure day included. Every generated plan
therefore carried one night too many, and every one of them checked out a day
after the traveler's own end date. The `nights + 1` rule was itself put in on
2026-08-09 to *fix* this symptom ("a stage dated past the departure day") — it
codified the off-by-one instead.

- ✅ **The prompt asks for one entry per night** (`routingGuidance`): exactly
  `nights` entries, numbered 1 to `nights`, no entry for the departure day, and
  — this is what the dropped entry used to carry — **the last night** must be in
  or next to the end location, because that is where the traveler leaves from
  the next morning. The day-count paragraph now also reaches the one-way branch,
  which never had one even though the count was validated for both.
- ✅ **`validateRouteShape` counts to `nights`**, not `nights + 1`, and its
  correction message asks for the last *night* in the end location.
- ✅ **`foldReturnDay()` catches a stray one anyway.** A prompt is a request,
  not a guarantee, so a plan that comes back exactly one entry too long has that
  entry **folded into the night before it, not deleted**: its date is already
  that night's `date_to`, and whatever was planned on it becomes that night's
  departure entries — precisely where the plan renders the checkout morning.
  Only the one off-by-one folds; two entries too many is nights misallocated,
  which still goes back to the model for a retry.
- ✅ **Plans already saved are repaired** by a migration doing the identical
  fold (`2026_08_24_090000_fold_the_departure_day_out_of_saved_plans`), guarded
  on `count(days) === trip_params.nights + 1` so a plan the traveler has since
  added nights to is left alone. It bumps `version`, so a tab holding the old
  document gets the conflict banner instead of quietly writing the phantom night
  back.
- ✅ **The share card counts nights too** (`TripPlanMeta::length`): it was
  counting day entries and calling them days, so a four-night plan unfurled as
  "4 days, 25 Aug – 29 Aug" — a count that contradicted its own date range.

Worth knowing before touching this again: the vehicle line multiplies its daily
rate by `days.length`, so this quietly corrected a 17-day rental billed as 18
days as well. And what is *still* not synced is the other direction — adding a
night in the UI does not update `trip_params.nights`, so the meta line keeps
saying "17 Nächte" over an 18-night plan. Left alone deliberately: `nights` and
`travel_period` are one statement from the interview, and moving one without the
other trades a wrong number for an inconsistent pair. See "Known gaps".

### 2026-08-24 — planning a trip without typing a word

Kaia asked good questions and offered exactly one way to answer them: a text
field. That is the wrong ergonomics for the first thing a traveler meets — on
a phone especially, where "how many nights?" costs a keyboard, a sentence and
a send. The interview is a slot-filling conversation with a handful of closed
answers per slot, so those answers are now buttons.

Two sources, deliberately different:

- **Openers under the greeting** (`chat.starters`) — six curated ways in,
  including the two Till named: *2-week round trip, 4x4 with rooftop tent* and
  its 3-week twin. One of them answers nights *and* vehicle in a single tap,
  which is why they are worded as whole trips rather than as a first question.
  One is a plain Namibia question, so the chips also say "Kaia answers things,
  not only plans them".
- **Answers under each question** — Kaia's reply now declares *what it just
  asked for* (`App\Enums\InterviewSlot`: nights, travel_period, interests,
  budget_tier, travelers, vehicle_type, start_end), and the frontend renders
  the chips for that slot.

The mechanism is `reply_to_traveler`, a new tool the interview must call for
every conversational turn — the general-Namibia answer as much as the next
interview question — with `tool_choice: any` forcing it. Every turn is now one
of four structured shapes with no prose branch to guess at, which is what
makes `awaiting` dependable enough to hang the flow on. A reply that names a
slot outside the enum, or names none, comes back as `null` and the traveler
gets the text field for that turn: the failure mode is a keyboard, never
vehicle types offered under a question about children.

Four decisions worth not undoing:

- **The chips live in the frontend, not in the model** (`lib/kaia-suggestions.ts`
  + `chat.suggestions.*` in all five `lang/*.json`). Generating them per turn
  would pay tokens and latency for wording that drifts between turns and can't
  be translated. The backend only names the slot.
- **A chip's label is the message it sends.** The transcript reads as if the
  traveler said it, and Kaia's own inference rules ("two weeks" → 14 nights)
  keep doing the work, rather than a second, silent encoding the model never
  sees.
- **Travel period is generated, not translated** — the current month and the
  five after it, via `Intl.DateTimeFormat` in the traveler's locale. It is the
  one slot whose answers move with the calendar, and a month is exactly the
  shape `ready_for_itinerary` wants.
- **One question per turn.** The prompt used to combine two missing fields
  into one sentence to save a turn; half of such a question has nothing to tap.
  The cap went from 4 questions to 5, and after that Kaia assumes a sensible
  default rather than asking a sixth time. A tap is cheaper than a sentence, so
  five taps beat three typed answers.

Also: a tapped answer does **not** refocus the input (`runKaiaRequest(false)`),
because focusing it opens the phone keyboard over the next set of buttons —
the one thing a tap-through traveler never asked for.

Tests: `tests/Feature/Kaia/InterviewSuggestionsTest.php` — a declared slot
reaches the chat, "none"/unknown/missing all come back as no slot, prose still
answers, and the interview call really does force a tool call and ship the
enum the chip sets are keyed on.

Not done here: multi-select chips (interests is the one slot where picking two
would be natural), and the chips stop at the plan — editing an existing plan is
already click-driven through the trip-params popup, but "add a night here" is
not offered as a suggestion.

### 2026-08-24 — a shared trip link says what it is

A `/trip/{token}` link exists for exactly one reason: to be sent to somebody.
Pasted into WhatsApp it unfurled as the site card — *NamibWay — The smartest
way to experience Namibia*, the same two lines the homepage produces — so the
recipient saw an advert for the platform where they should have seen the trip
they were being shown. The one thing the card could not say was the one thing
it was for.

The card is now built from the plan (`App\Support\TripPlanMeta`):

- **og:title** — `Trip plan: {the plan's own title}`, falling back to
  *Namibia trip plan* when a plan has none.
- **og:description** — `Shared with you on NamibWay · 12 days, 25 Aug – 5 Sep
  2026 · Windhoek → Sesriem → Swakopmund → …. Open the link for the full
  day-by-day itinerary.` Consecutive days at one place are one stop (three
  nights in Etosha is not "Etosha → Etosha → Etosha"), the route is cut to
  five stops with an ellipsis, and the year is dropped from the first date
  when both ends carry the same one.

Two things worth not undoing. **The meta is read in the root template, not in
the page component** — an unfurler runs no JavaScript, so anything a `<Head>`
in Vue sets is invisible to the only reader that matters. And it is a generic
`meta` prop (`title`, `description`) with the site card as the fallback, so
the next page that deserves its own preview — a listing, a destination — needs
a controller line and nothing else. `resources/views/app.blade.php` also emits
a plain `<meta name="description">` now, which it never had.

Every field is derived defensively: `plan_json` is whatever the model produced
when the plan was saved, so a missing date, location or variant only shortens
the card. Held down by `TripPlanLinkPreviewTest`, which also asserts that every
other page still gets the site card.

**The image is the route** (`App\Services\Trip\TripCardImage`, served by
`/trip/{token}/card.png`): the plan's stops drawn as a numbered map on the
right, the plan's name and length on the left, in the same night-sky-over-dunes
the site card uses so the two read as one family in a chat thread. Drawn with
GD rather than a headless browser, because an unfurler waits about a second and
then shows nothing — there is no queue to wait for and no browser to start.

What the drawing had to get right, all of it visible in the first attempts:

- **The route is fitted to itself**, not to a fixed Namibia box, with a
  three-degree floor so two stops an hour apart don't fill the card at street
  scale. Longitude is scaled by cos(latitude) before fitting, or the country
  comes out stretched sideways.
- **A place is one dot.** A round trip returns to Windhoek, and drawing the
  return as a second marker put two dots on one pixel with the caption written
  twice. The dashed line follows every leg; the markers are the distinct
  places, numbered by first arrival.
- **A label that would land on another is dropped** — tried below the marker,
  then above it, then given up on. That is what keeps a fifteen-stop route
  readable, and what got Otjiwarongo its name back next to Twyfelfontein.
- **Everything is drawn at 2× and scaled down.** GD antialiases neither thick
  lines nor ellipses, so the markers were visibly jagged at 1×.

The URL is minted from the **read-only** token whichever link the visitor came
in on — the same rule as `shareUrl`, because a picture pasted into a group chat
must not carry write access with it. The route is open and unauthenticated on
purpose: a crawler has no session, and a card behind `auth` is a broken card.
Drawing costs ~0.7 s, so it is cached for a day keyed on the plan's `version` —
an edited plan redraws without anything having to remember to clear it.

One thing fixed in passing: the **PDF's** route map used a destination+city
index written out in the controller, from before a day's location became a
place — so a place-only stop (Sesriem, Etosha National Park) was silently
missing from the printed map. Both renderers now share
`App\Services\Trip\PlanWaypoints`, which resolves a location the same way the
interactive map does.

### 2026-08-23 — a city is an address, a place is where you go

#198 gave parks, reserves and landmarks rows in `cities` so a lodge standing in
one had somewhere to be filed. It solved the filing problem by breaking the
noun: a table called cities held Etosha National Park, `population` and
`area_km2` were meaningless on those rows, and the question a listing has to
answer **twice** could only be answered once — *what is your address* and
*where do you sit for a traveller*. A camp on Onguma is posted to Outjo and is
nowhere near it.

So the two are separated, in three steps that each ship green:

- **`cities`** is the address, classified by `CityType` — the administrative
  gazetteer, towns down to settlements, what a street address resolves to.
- **`places`** is where a traveller goes, classified by `PlaceType` — national
  park, nature reserve, landmark, **and town**. A listing carries both.

The decision that makes this cheap rather than expensive: **everything Kaia
routes on is a place**, so the trip-relevant towns get a place row of their own
and `cities.place_id` links the two identities. Windhoek is one dot on the map
seen twice — as an address, and as a stop on a trip. Without that, the driving
matrix and every day location would have become a polymorphic city-or-place
pair: four columns and a doubled lookup in the hottest code in the product.

What moved, concretely:

- `city_driving_hours` → **`place_driving_hours`**, existing rows carried over
  through `cities.place_id` so not one OSRM request was repeated. The command
  is `namibway:backfill-place-driving-hours` and its scope is simply *every
  place* — the type rule it used to need is gone, because a place exists
  precisely when it is somewhere a traveller goes.
- `ItineraryService::canonicalCity()` → **`canonicalPlace()`**, resolving day
  locations against `places`. Day-location *strings* are unchanged — a place
  carries the same name its city did — so saved plans keep working.
- The AI catalog sends **`place`** instead of `city`, and the prompt says to
  copy that value. Sending the address city is what would put a traveller in
  Outjo.
- `ListingObserver` fills `place_id` from the listing's city, and **never
  overwrites one set by hand** — that override is the entire reason the two
  columns exist.

Step 3 finished the same day: the tourism rows are out of `cities`,
`legacy_city_id` is dropped, and `CityType` is back to six settlement cases
with no `isSettlement()` — every case is one. `listings.city_id` is
`nullOnDelete`, so a lodge filed in a park lost an address it never really had
and kept its place, which is the correct outcome rather than a loss.

One hole in step 2a's rule showed up and was closed while doing it: it took the
old driving-matrix scope (larger settlement types, plus anywhere already
hosting a published listing), and on a thin catalog that drops Sesriem,
Solitaire and Ai-Ais — officially settlements, and exactly where a Sossusvlei
or Fish River traveller sleeps. **Belonging to a tourism area is the stronger
signal**, because somebody curated that city into Sossusvlei on purpose and it
does not depend on the catalog; a city with a `destination_id` now gets a place
too.

Two things the split turned up that were quietly broken by it and are fixed:
the Explore filter for "Etosha" missed a camp with no city at all
(`Listing::scopeFilterBy` searched only the address side), and the homepage
handed the frontend `area = null` because `place_id` was not in its column
whitelist, so the relation could not load.

**Filament followed the same day.** Settings now holds four screens instead of
three: **Cities** is addresses again (with a `Place` column and an "Is a place"
filter, so the trip identity is visible from the address side), **Places** is
the new one, **Destinations** is the area above them, **Regions** unchanged.
The places table has a *Routable* column, because a place without coordinates
is silently absent from the driving matrix and that is not something to find
out from a broken plan.

A listing now has **two** location fields in both panels: *City (address)* and
*Place*. Empty, the place follows the city; set, it wins. That is the Onguma
case in one control — postal address in Outjo, traveller in Etosha — and it is
the only thing a content manager has to understand about the split.

**The village gap is closed** (`Place::forCity`). Which cities got a place was
decided once, at migration time, from what the catalog looked like then — and
that rule cannot know what opens next year, so the first published listing in a
village nobody had thought about would have had no place at all, which means no
day location, no driving times and no map pin. The answer is not a smarter rule
but a **later** one: a place exists when somewhere turns out to be a place, and
a business opening there is the evidence. A draft mints nothing; publishing it
later does.

That created a second problem worth knowing about, fixed in the same commit: a
place minted from a village inherits the village's coordinates, which are
usually null, and `namibway:backfill-place-driving-hours` refuses to run while
any place lacks them — so one new listing could have blocked the matrix for
everything. `namibway:backfill-city-coordinates` now copies what it geocodes
onto the places those cities are, filling blanks only, so it heals nightly and
a hand-set value survives (a park's centre is not its nearest town's high
street).

### 2026-08-23 — Kaia can send you to look at something

The table got a model, a screen, 47 rows and a way into the trip plan, so the
section above stops being a schema and starts being a feature.

**How it reaches a plan.** A day's `activity` may now name a bookable activity
from the catalog *or* an entry from a second list the model is given, "things to
see". Both are resolved by name in `resolveReferences`; the model is not asked
which kind it picked. The prompt says when to reach for one: a day that is
mostly a drive, a night where the catalog has no activity near the bed, or an
interest the catalog cannot serve.

Three shapes worth keeping:

- **The short form is all the model sees** — name, type, place, one line, and
  `visit_minutes` where known. The long description never enters the prompt.
  That is the same discipline as `MAX_CANDIDATES_PER_TYPE`, and for the same
  reason (the OOM of 2026-08-09). Capped at `MAX_ATTRACTIONS`.
- **Only published rows with coordinates.** Without a coordinate it cannot be
  drawn or checked against where the traveller is, so offering it would be
  offering a name.
- **The resolved entry carries no slug**, deliberately: the plan opens a
  *listing* preview from that field and an attraction has no listing page. The
  row still shows its name, duration and place. An attraction detail page is
  the obvious follow-up.

**The 47 rows** (`2026_08_23_160000_seed_attractions`) are published and marked
`ai_generated`. The names, categories and geography are right — nothing is in
the wrong region — but the coordinates were written from knowledge rather than
read off a map, and two kilometres out is a wrong turn on a gravel road. So
`namibway:verify-attraction-coordinates` checks each one against OpenStreetMap
and lists only the ones that disagree by more than 3 km, because checking 47 by
hand is a job nobody finishes. **Report-only by default and that is the point:**
Nominatim is often worse than we are for a rock-art site or a farm gate — it
returns the nearest town centre, or a hotel that borrowed the name — so a
disagreement means "somebody look", never "OSM wins". `--apply` exists for after
you have looked.

`entry_fee` is null on every row on purpose: an invented fee is worse than none,
and they change. `requires_4x4`/`requires_permit` are set only where the answer
is a standing fact about the site (Sandwich Harbour, Kolmanskop, Twyfelfontein)
and left null everywhere else, which means "not established" and not "no".

### 2026-08-23 — a thing you go and look at has a table

First step of the section above, and only the first: the schema, nothing that
reads it yet.

`attractions` (`2026_08_23_100000_create_attractions_table.php`) plus
`AttractionType` — nine coarse categories (natural feature, geology, wildlife,
rock art, palaeontology, history, culture, museum, viewpoint), deliberately
too few to have to think about before filing something.

Why a third table rather than either of the two that already exist, since both
were argued for and both are wrong:

- **Not another `PlaceType`.** A place is a *container*: a day's location, a
  node in the driving matrix, an Explore filter value, the thing a listing is
  filed under. Adding a meteorite there makes it all four.
- **Not a `Listing`.** The four listing types have a price and a partner, and
  the booking core reaches into that table. Same reasoning that keeps
  `menu_items` out of `bookable_units` — a small table at the edge the booking
  core never learns exists.

It adds no third location concept: `city_id` is the place it sits in or
nearest to, and region and area derive through it exactly as
`Listing::getRegionAttribute()`/`getAreaAttribute()` derive theirs. Entries that
are *both* keep both rows — Twyfelfontein, Sossusvlei and Kolmanskop are
already places because listings are filed there; the place row stays the
container, the attraction row carries what it costs, how long you need and how
you get in.

Three shapes worth not undoing later:

- **Two texts with two jobs.** `summary` is the one line that may go to the
  model beside a name and a coordinate; `description` is what a detail page
  renders and must never enter the itinerary prompt. That prompt is capped for
  a reason (`MAX_CANDIDATES_PER_TYPE`, and the OOM of 2026-08-09).
- **Null is "not established", not "no".** `requires_4x4 = false` is a claim
  somebody checked; `null` is a blank. Same care as the content-source ladder,
  where a null source is untouchable rather than lowest-rank.
- **The operational columns are the point** — `visit_minutes`, `entry_fee`,
  `requires_4x4`, `requires_permit`, `access_note`, `best_time_note`. What a
  model already knows about the Hoba meteorite is free and differentiates
  nothing; which track and what it costs is what turns an answer into a day in
  a plan.

Deliberately left out so they get decided rather than defaulted: a curated
attraction↔listing relation (proximity comes off coordinates first, a hand-made
link is an override on top), and the `pending_image`/`pending_gallery` review
queue listings carry — `description_source`/`photos_source` are enough to hold
the publishing rule until something actually fills this table from a third
party.

Nothing reads the table yet: no model, no Filament resource, no seed, and Kaia
is untouched. Verified only that it migrates, rolls back and migrates again on
Postgres.

### 2026-08-19 — the traveler is shown the area, not the region

Follow-up to the entry below, and the co-founder's actual point once Onguma was
selectable: **"Onguma Nature Reserve (Oshikoto)" is of no interest to anybody
planning a trip.** They want to read "Onguma (Etosha)". The same holds for
Sesriem and Solitaire, which are Sossusvlei to everyone who goes there.

The political region was the only thing a place had to offer, and it answers a
question nobody planning a holiday asks — which administration the property
pays its levies to. So a place now carries a second, nullable link beside it:
its **area**.

Three levels, split by what each is for:

| | Example | What it is for |
|---|---|---|
| **Area** (`destinations`) | Sossusvlei | What the traveler says. Filter, cards, the label under a place name |
| **Place** (`cities`) | Sesriem, Solitaire, NamibRand | Where a bed is. Driving times, the day's position in the plan |
| **Sight** (a `landmark` place) | Deadvlei, Dune 45 | Where you go, not where you sleep. Hangs off an activity |

The area is the existing `Destination` — Etosha, Sossusvlei, Swakopmund and
nine more are already there with a name, blurb and photo, and are already what
the homepage offers. No third entity; `cities.destination_id`, and the two
questions a place answers stop being confused with each other.

What changed with it:

- **The destination card finally shows what it promises.** It filtered on the
  political region, so "Etosha" meant Kunene — the Skeleton Coast included,
  every Onguma lodge excluded, because Onguma is in Oshikoto. It now filters on
  the area, and `Listing::scopeFilterBy`'s `region` parameter — deliberately a
  "roughly where" filter — matches an area name as well as a place and a region.
- **Everything a traveler reads prefers the area**: the trip plan's stage
  subtitle, the listing page's bracket after the town, the Explore select
  (relabelled from Regions to Areas in all five languages). The political
  region is only the fallback, for a place in no area yet, and it disappears on
  its own as the content team fills them in.
- **Both stay in the admin**, and the place form says which is which: Region
  (political) is marked administrative, Area is marked as what the traveler
  sees. Destinations gained a Places count, so it is visible at a glance what
  an area actually gathers.
- The migration files the obvious ones (Etosha ← Onguma, Ongava; Sossusvlei ←
  Sesriem, Solitaire, NamibRand; and so on) and **only fills blanks**. Outjo is
  deliberately left alone: whether the gateway town to Etosha South counts as
  "Etosha" is a content decision, not a migration's guess.

A rule worth keeping, so this does not turn into a second gazetteer: **a sight
is created when something bookable happens there.** Deadvlei with no tour in
the catalog is an empty row.

### 2026-08-18 — a lodge in a park has somewhere to be filed

The co-founder went looking for Onguma, and for Etosha as the area Onguma is
in, and found neither: `cities` was seeded as Namibia's administrative
gazetteer — the ~105 proclaimed municipalities, towns and settlements — so a
property standing in a park, on a private reserve or at a landmark had no
place to be filed in. Half the country's lodges are in exactly that position.

That is not a cosmetic gap in a dropdown, because `city` is what the trip plan
is built out of: it is the day's location, the point Kaia measures driving
hours between (`city_driving_hours`), and the Explore filter. A listing with no
city never enters a plan at all; a listing filed in the nearest town enters it
in the wrong place — the demo seed still puts Etosha lodges in Outjo, ~100 km
away, and a plan built from that is wrong by an hour's drive without ever
looking wrong.

Fixed by widening what a place *is* rather than adding a second location
entity beside the first:

- `SettlementType` → `PlaceType`, plus `national_park`, `nature_reserve` and
  `landmark`. One taxonomy, so everything downstream — day locations, the
  driving matrix, the Excel `city` column, the nearest-place matching the
  namibweb importer does from GPS — keeps working with no new concept.
- Those three types are in `PlaceType::inDrivingMatrix()`, so a park is routed
  between like a town. Villages and settlements stay out, as before.
- 22 places seeded (`2026_08_18_110000_seed_tourism_places.php`): Etosha,
  Onguma, Ongava, Sossusvlei, Sesriem, Solitaire, NamibRand, Twyfelfontein,
  Palmwag, Skeleton Coast, Spitzkoppe, Cape Cross, Sandwich Harbour, Fish
  River Canyon, Ai-Ais, Kolmanskop, Aus, Waterberg, Erindi, Bwabwata, Mudumu,
  Nkasa Rupara. Coordinates are deliberately left null — the same
  `namibway:backfill-city-coordinates` that geocoded every settlement fills
  them, rather than having numbers typed in from memory.
- Called **Places** everywhere a person reads it (both panels, the Excel
  instructions sheet), because "City" is what told the content team a lodge in
  a national park had nowhere to go.
- Kaia resolves the short name too: `ItineraryService::canonicalCity()` falls
  back to an alias index built from the tourism areas ("Etosha" → "Etosha
  National Park"), because that is what a traveler types and what the model
  has read everywhere it has ever read about Namibia. Ambiguous short forms —
  one that collides with a real place or with another area — are dropped
  rather than guessed at. The prompt now says to copy the catalog's city value
  character for character instead of "never name a park", which was only true
  while parks were not places.

**After deploying, in this order:** `namibway:backfill-city-coordinates`, then
`namibway:backfill-city-driving-hours` — the second refuses to run while any
in-scope place has no coordinates, which is what keeps the matrix from
silently having holes.

Left open: nothing splits Etosha into its gates. A single park row means a
plan measures a drive to Namutoni as if it went to the middle of the park —
worth revisiting only once real Etosha listings exist and the error shows up
in a plan.

### 2026-08-16 — a restaurant says which channels it takes online

Follow-up to the entry below, from the first question asked of it once it was
live: *where do I switch this on and off?* Nowhere — both channels were derived,
and both derivations conflated **having** something with **selling it over the
internet**.

- ✅ **`accepts_table_reservations` and `accepts_orders`** on `listings`, both
  default true, both under `accepts_inquiries`, both meaningless outside a
  restaurant. Shared between the panels as `RestaurantChannelSchema` — /admin's
  Visibility tab and the partner panel's Settings section, the same arrangement
  the room-type and menu schemas use.
- ✅ **A menu has two jobs and only one of them is a commitment.** Entering a
  card used to switch ordering on by itself, so a restaurant that wanted its
  menu *shown* was made to take orders. With ordering off the card now renders
  on the listing page as something to read — which is what makes the switch
  worth having rather than just a way to hide work somebody did.
- ✅ **`Listing::requestKinds()` is the single answer** to what a property may
  be asked for. The page draws its tabs from it and the controller enforces the
  same list on the POST, so a channel switched off is a rule rather than a
  hidden tab. `storeBatchInquiry` filters on it too.
- ✅ **Ordering additionally needs something to order.** A toggle switched on
  over an empty menu counts as off; a promise the page cannot keep is not a
  state worth having.
- 6 request tests plus `RestaurantChannelTogglesTest`, which goes through
  Livewire rather than calling the closure directly — the risk in a
  `visible()` closure is never the logic, it is whether Filament's own
  evaluation reaches it.

### 2026-08-16 — a restaurant is asked for a table or for dinner

Until now every listing type was asked the same question — check-in, check-out,
adults, children — which for a restaurant meant a departure date it does not
have and a form that could not express the two things it actually sells.

- ✅ **`App\Enums\InquiryKind`: `booking`, `table_reservation`, `order`.** A
  property of the *request*, not of the listing's vertical, which is the
  distinction `BOOKING_BEYOND_ROOMS.md` §6 asks for: the core never learns to
  ask "which vertical am I serving?", only "what shape is this request?".
  Everything that existed before is a `booking` by default, so nothing was
  backfilled and no existing reader changed.
- ✅ **A table is a date and a time**, both required, with no departure —
  `inquiries.arrival_time`. The customer-website form had been folding the time
  into the free-text `travel_dates` ("2026-09-01 at 19:30") explicitly to avoid
  a migration; that was right for one caller and wrong for two, so both front
  doors now write the column and `SiteEnquiryController` stops folding.
- ✅ **An order is a list, and nothing else.** No dates, no times, no party
  size — the fields are refused rather than defaulted, so a row never carries a
  check-in nobody asked for.
- ✅ **`menu_items`, listing-scoped, not `bookable_units`.** A room type is a
  thing with a *count*; a plate of kudu has none, and putting it in
  `bookable_units` would mean inventing a unit count and then teaching the
  inventory writer to ignore it. Small table at the edge; the booking core never
  learns it exists.
- ✅ **The browser sends ids and quantities. Nothing else.** Names, prices and
  the total are read from the menu in the database by
  `App\Services\Booking\MenuOrder` and frozen onto `inquiry_items` — the same
  rule `reservation_nights` follows, so a price rise next week cannot change
  what somebody ordered last week.
- ✅ **Confirming a restaurant request no longer alerts the team.**
  `StayPromoter` used to try to write every confirmed request to the nights
  calendar and mail an incident when it could not; a table booking has no
  check-out, so every one of them would have produced a false alarm. The kind
  now says whether it becomes a stay at all.
- ✅ **Dinner does not use up the one active booking request.**
  `ActiveRequestGate` counted every inquiry, so ordering a burger would have
  locked the traveller out of requesting anywhere to sleep until the kitchen
  replied. It now counts only stay-shaped requests, in both directions. A
  judgement call, made rather than asked: the rule rations speculative requests
  at lodges, and shipping it the other way would have made the feature unusable.
- ✅ **Both panels can maintain a menu** — a "Menu" tab in /admin (relationship
  Repeater) and `MenuItemsRelationManager` in the partner panel, sharing
  `MenuItemSchema`, and shown only for a restaurant. The tab's visibility is one
  closure, not `visible()` followed by `visibleOn('edit')` — the latter is
  *implemented as* `visible()`, so the second call silently replaces the first
  and the tab would have shown on every lodge in the panel.
- ⬜ **Covers per sitting.** A restaurant's real inventory is seats at a time,
  which is a slot on the ARI calendar (`BOOKING_SYSTEM.md`, "Time inside a
  day") and not this. Today a table request is a question the restaurant
  answers, exactly like a request to a lodge on somebody else's PMS.
- ⬜ **The trip plan does not order.** A restaurant on a day plan is still
  decorative — `TripController::store` only ever creates inquiries for
  accommodation. Ordering from the plan is the obvious next step and is
  deliberately not in this change.
- ⬜ **The website builder's `price_list` block is still typed by hand.** Its
  docblock says a menu is "something no system of ours holds", which is now half
  false: a listing-backed site could import the menu the way it imports
  everything else. Left alone rather than half-wired.

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

- ⬜ **The trip's meta line does not follow its own edits.** `trip_params`
  carries `nights` and `travel_period` as one statement from the interview, and
  the plan header prints both. Adding or removing a night in the timeline
  changes neither, so a 17-night plan grown to 18 still reads "17 Nächte,
  1–18 Januar 2027". Syncing `nights` alone is not the fix — it would leave the
  period contradicting it — so the honest version updates both from the days
  themselves, which means deciding whether an edited plan may move its own end
  date or whether growing it is a regeneration. Found while fixing the phantom
  departure night on 2026-08-24.

- ✅ **Fuel and supplies as stops on a leg.** Built the same day it was
  raised: `supply_points`, `SupplyStopFinder`, the **Stock up** line under
  **On the way**, and 57 seeded towns. See the entry above. What is left of it
  is content, not code — every seeded row is unverified and none has opening
  hours, and the corpus is what decides whether a gap is reported honestly.

- ⬜ **The road between two stages is mostly empty of content.** 47 attractions
  is enough to prove the "On the way" line works and not enough for it to fire
  on the legs travellers actually drive: Windhoek → Waterberg, the example that
  prompted the feature, turns up nothing. Cheapest content work in the product,
  and the one place where a single row is directly a thing a traveller stops
  for. Raised 2026-08-24.

- 🟡 **Price units are recorded but nowhere entered.** The column, the
  editors, the payloads and the arithmetic landed in session 16; no listing
  has a value yet, so every price still prints exactly as it did before.
  Same shape as the room types: this is content work now, not code. Two code
  follow-ups were deliberately left out — the budget tiers
  (`ItineraryService::budgetTier`, `Listing::scopeFilterBy`) still band on
  the raw `price_from`, so a per-person rate lands a tier too low, and
  Kaia's catalog isn't told the unit either.

- ✅ **Booking facts on a plan entry.** Done 2026-08-17 (item 8 from
  BOOKING_BEYOND_ROOMS.md §7.10). `ItineraryItem` rows now bridge a
  `SavedPlan` to its `Inquiry` for each accommodation in the booking.
  Created by `TripController::store` at the moment requests are sent, with
  the exact check-in/check-out span derived from the plan's dates. The
  stay card on the plan page now shows a colour-coded booking status badge
  (pending / awaiting partner / confirmed / cancelled) seeded from the
  `bookings` prop that `SavedPlanController::show` passes down. JSON stays
  for planning; rows are what link a plan entry to the outside world.

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
- 🟡 **Things to see, not only places to stay** — a point-of-interest layer
  so Kaia can answer "what is worth seeing here / along this road?", not
  only "where do I sleep and what can I book?". Proposed 2026-08-23. The
  geography under it exists since 2026-08-18/19 (tourism areas are places,
  areas are shown and sent to the model); what is missing is the thing you
  go and look at, which is not a container for a listing. See the dedicated
  section above for why it is a separate table rather than another
  `PlaceType`, and for the prompt-size and content-cost cautions. The
  `attractions` table itself landed 2026-08-23; nothing reads it yet —
  no model, no admin screen, no content, and Kaia is untouched.
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

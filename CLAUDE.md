# NamibWay (working repo name: travel-platform)

AI-assisted travel planning & booking platform for Namibia. Domain: namibway.com.
Slogan: "The smartest way to experience Namibia."

## What this is
A conversational, magazine-style travel platform. Instead of search forms, travelers chat with an AI travel companion ("Kaia") that interviews them and generates a bookable, multi-part itinerary (accommodation + activities + restaurants + routing). A classic filter/search UI exists as a secondary path for browsing. The platform then manages the request/booking flow with partners (lodges, activity operators, restaurants), and offers after-sales support (checklist, on-trip help, feedback).

Full context (concept doc, product vision, business plan, tech decision rationale) exists in project docs — see `/docs` once copied in. This file is the condensed, load-bearing summary for development.

## Tech stack (decided — do not re-litigate without discussion)
- **Backend:** Laravel 12 (PHP)
- **Frontend:** Vue 3 + Inertia.js + TypeScript + Tailwind CSS, Vite build
- **Database:** PostgreSQL
- **Admin/content CMS:** Filament (auto-generated admin panel from Eloquent models) — this is how the non-technical local co-founder manages listings/content
- **AI:** Claude API, same structured-output/tool-calling pattern already used in the team's Wisherful product (item extraction from text/URL/image) — reuse that integration pattern for the travel interview + itinerary generation
- **Queue/rate-limiting:** Laravel Queues + Redis + Horizon — implements request-governance (see below)
- **Media storage:** Cloudflare R2
- **PDF output:** Laravel's existing PDF service (reused from RentalHandover) for the trip checklist
- **Payments (Phase 2, not MVP):** Stripe, incl. Customer Portal
- **Multi-tenancy:** reuse pattern from RentalHandover if partner-scoped access is needed
- **Multilingual:** reuse pattern from RentalHandover (DE/NL/EN/FR); MVP ships English-only, but model content as localizable from day one
- **Deploy:** every push to `main` auto-deploys — a GitHub Actions workflow (`.github/workflows/deploy.yml`) SSHes into the server, `git pull origin main`, then runs `deploy.sh` (composer, npm build, migrations, cache rebuild, permissions, queue restart). There is no separate manual deploy step; merging/pushing to `main` immediately ships to production.

### Deploy incidents — fix `deploy.sh` itself, don't just patch the server by hand
When a production outage traces back to a flaw in the deploy process (not just a code bug), fix `deploy.sh` and note it here — don't only walk the user through one-off manual recovery commands.
- **2026-08-01:** site-wide 500 (`Class "view" does not exist"`, root cause `Class "Laravel\Sanctum\Sanctum" not found`). `deploy.sh` decided whether to run `composer install` by checking `git diff HEAD@{1} HEAD` (the reflog) instead of the commit range actually just pulled — an unreliable check that skipped `composer install` on the deploy that added `laravel/sanctum`, leaving `vendor/laravel/sanctum` missing, which broke package discovery and cascaded into every service provider (including `view`) failing to register. Fixed by diffing `$OLD_COMMIT`..`$NEW_COMMIT` (captured directly around the `git reset --hard` in the deploy script) instead of the reflog, for both the composer.lock and package.json/package-lock.json checks.

Rejected alternative: Next.js/Payload — would require re-solving problems (AI integration, multi-tenancy, i18n, storage, PDF, payments, deploy) already solved in the team's proven Laravel stack. Not worth it for a solo 3-month build.

## The core product mechanic — read before building the booking flow
The single most important, not-yet-fully-solved design problem: **turning an AI-generated plan into confirmed bookings without flooding partner owners with speculative requests.**

Rules to implement via the Redis/Horizon queue:
- One active request pipeline per traveler at a time (rate-limited) — a traveler cannot open a new provider request while others are pending.
- Stage confirmations: lock accommodation first, then layer in activities/restaurants once the route is fixed.
- Require a real commitment signal (name, contact, travel dates — no payment) before requests go out, to filter casual browsing.
- Consider a "soft hold with expiry" concept with owners rather than a bare enquiry.
- Make responding low-effort for owners (pre-filled details, one-click confirm).

## AI engine notes
- Claude should NOT be the source of truth for hard logistics facts (driving distances, night-driving rules, fuel stops). Feed these from a maintained routes/distance dataset via tool-calling — don't trust model memory for specific Namibian geography.
- Cost is not a meaningful constraint: modeled at roughly $0.15–0.50 per completed planning session (Haiku for interview turns, Sonnet for itinerary generation), which is small relative to typical commission revenue per booking.

## Business model (for context, not to build yet)
Revenue: commission on bookings (modeled minimal, ~5%, deliberately below OTA rates), government/tourism-sector funding, advertising (featured partner listings), after-sales/e-shop (Phase 2/3, deferred).

## Partner landscape (for Workstream 4 / interfaces, to validate with real partners)
Common systems in the Southern African lodge market: ResRequest (dominant PMS, has a free API called ResConnect), NightsBridge (channel manager), HOPE Software / hopeCloud (Namibia-specific, handles Tourism Levy + NTB/HAN reporting), Wetu (guest-facing itineraries, may already hold partner content worth reusing). Namibia Wildlife Resorts (state-run camps in Etosha, Sossusvlei, Fish River Canyon) has a known-unreliable booking system — treat as a special case, likely the source of the "marked full but actually available" problem that motivated this whole project.

## MVP scope (3-month target, 5 parallel workstreams)
1. **Technology & Platform Foundation** — Laravel/Inertia/Vue/Postgres base, deployed via the adapted deploy script, seeded manually via Filament.
2. **Core Engine** — Claude-driven interview → itinerary generation; Redis/Horizon request-governance.
3. **Content Creation** — Filament admin + R2 media, partner content intake (photos with rights confirmation, descriptions, USPs, pricing).
4. **Interfaces / Partner Integration** — per-partner integration (API where available via ResConnect, manual via Filament otherwise).
5. **After-Sales / Up-Sell** — trip summary/feedback in Postgres; e-shop deferred, but data model should leave room for it.

## UX reference
An interactive HTML/JS prototype exists (`namibia_travel_prototype.html`, included in this handoff) demonstrating: hero + live AI chat interview → itinerary variants → booking-request-queue animation (illustrates the one-active-request-at-a-time rule) → after-sales cards → browsable "Explore" rows with a category/date/keyword filter bar (expandable "More filters" for region/budget). Treat it as a UX/interaction reference to port into real Vue components — it's vanilla JS with inline demo data, not production code, and its branding is a placeholder ("Namibia Travel & Life" — a proper logo file still needs a clean SVG export, current PNGs have a rendering issue).

## Where to start
Recommended first build step: scaffold the Laravel + Inertia + Vue project, set up Postgres + Filament, and get one Eloquent model (e.g. `Listing`) manageable end-to-end through the Filament admin before touching the AI engine. Confirm this sequencing before diverging.

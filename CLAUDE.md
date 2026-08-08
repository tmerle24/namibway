# NamibWay (working repo name: travel-platform)

AI-assisted travel planning & booking platform for Namibia. Domain: namibway.com.
Slogan: "The smartest way to experience Namibia."

## What this is
A conversational, magazine-style travel platform. Instead of search forms, travelers chat with an AI travel companion ("Kaia") that interviews them and generates a bookable, multi-part itinerary (accommodation + activities + restaurants + vehicle + routing). A classic filter/search UI ("Explore") exists as a secondary path for browsing. The platform then manages the request/booking flow with partners (lodges, activity operators, restaurants), and offers after-sales support (checklist, on-trip help, feedback).

## Companion docs in this repo
This file is the condensed, load-bearing summary. The detail lives next to it:
- `DEPLOYMENT.md` (DE) — local setup, production install, Supervisor/Horizon, scheduler cron, disaster recovery, troubleshooting.
- `TRAVEL_PLAN.md` — working notes + backlog for the Reiseplan (trip plan) flow, the flagship feature. **Read its "Known gaps / next up" before touching the itinerary UI.**
- `MOBILE_APPS.md` — the Capacitor iOS/Android shells, icons, splash screens.
- `WEBMAIL.md` (DE) — Roundcube setup for `webmail.namibway.com` on the shared partner mailbox.
- `namibia_travel_prototype.html` — the original vanilla-JS UX prototype. Historical reference only; most of it is ported to Vue now (see "UX reference" below).

## Tech stack (decided — do not re-litigate without discussion)
- **Backend:** Laravel 12 (PHP 8.4)
- **Frontend:** Vue 3 + Inertia.js + TypeScript + Tailwind CSS 4, Vite build; Leaflet (+ markercluster) for maps, vuedraggable for the day list, vue-i18n for UI strings
- **Database:** PostgreSQL (17 in CI)
- **Admin/content CMS:** Filament — two panels: `/admin` (`AdminPanelProvider`) for the team, `/partner` (`PartnerPanelProvider`) for partner-scoped listing/inquiry management
- **Auth:** Fortify (incl. 2FA), Socialite (Google/Facebook), passkeys
- **AI:** Claude API via `App\Services\Kaia\AnthropicClient` — structured output / forced tool calls, same pattern as Wisherful
- **Queue/rate-limiting:** Laravel Queues + Redis + Horizon (Supervisor-managed)
- **Media storage:** Cloudflare R2 (`r2` disk). Legacy uploads on the local `public` disk predate 2026-08-02 — see `Controller::resolveMediaUrl`
- **Backups:** spatie/laravel-backup → separate **non-public** R2 bucket (`r2-backups`), AES-256 encrypted, nightly 02:00, failure-only mail notifications. `restore.sh` is the counterpart to `deploy.sh` for a total server loss
- **PDF output:** Laravel PDF service (trip plan PDF, partner API guide, listings partner handbook)
- **Public API:** `/api/v1` (Sanctum + `EnsureApiClientActive`), documented with Scribe; `ApiClient` model gates access
- **Mobile apps:** Capacitor 8 shells for iOS + Android wrapping namibway.com, with an offline bootstrap page (`ios-shell/index.html`). PWA icons/splashes derived from `public/images/pwa/icon-512.png` — see `MOBILE_APPS.md`
- **Payments (Phase 2, not MVP):** Stripe, incl. Customer Portal
- **Multilingual:** `config/locales.php` — `en, de, nl, fr, es`. Content is translatable via spatie/laravel-translatable (`Listing`, `Destination`, `RouteTemplate`), UI via `resources/js/lang/*.json`. Ships English-first
- **Deploy:** every push to `main` that passes CI auto-deploys — `.github/workflows/deploy.yml` triggers on the `tests` workflow completing with `conclusion == 'success'` on `main`, then SSHes into the server, `git pull origin main`, and runs `deploy.sh` (composer, npm build, migrations, cache rebuild, permissions, queue restart, PHP-FPM reload). There is no separate manual deploy step; a green `main` immediately ships to production.

Rejected alternative: Next.js/Payload — would require re-solving problems (AI integration, multi-tenancy, i18n, storage, PDF, payments, deploy) already solved in the team's proven Laravel stack. Not worth it for a solo 3-month build.

### Local dev & CI
- `composer setup` once, then `composer dev` (serves app + queue + vite; ports configured in `.claude/launch.json`). Postgres/Redis via `docker-compose.yml`.
- `composer ci:check` runs exactly what CI runs: eslint, prettier `--check`, `vue-tsc`, then `composer test` (pint `--test`, phpstan, `artisan test`). Run it before pushing — CI red means no deploy.
- Other workflows: `build-android-apk.yml`, the `scrape-*`/`discover-*` scraper workflows (manual `workflow_dispatch`, optionally committing JSON into `data/scraped/` via `commit-artifact.yml`).

### Deploy incidents — fix `deploy.sh` itself, don't just patch the server by hand
When a production outage traces back to a flaw in the deploy process (not just a code bug), fix `deploy.sh` (or the deploy workflow) and note it here — don't only walk the user through one-off manual recovery commands.
- **2026-08-04:** `deploy.yml` had no `concurrency` group, so two merges to `main` landing close together (observed 9 seconds apart) fired two overlapping `workflow_run` events, each SSHing into `/var/www/namibway` and running `deploy.sh` in parallel against the same directory — confirmed via the Actions run history (one of the two concurrent runs failed outright, run id `30813237154`, commit `701e4a3d`). Fixed by adding `concurrency: { group: production-deploy, cancel-in-progress: false }` to `deploy.yml` so overlapping runs queue and execute sequentially instead of racing. Note: this explains failed/racing deploy runs; it does NOT fully explain the separately reported symptom of "workflow shows green but the live site doesn't reflect the change" — if that recurs, suspect the same PHP-FPM/opcache class of issue as 2026-08-02, or a CDN/browser cache in front of namibway.com, and check the actual deployed commit on the server (`git -C /var/www/namibway log -1`) against `main`.
- **2026-08-03:** discovered `deploy.yml` triggered on every push to `main` regardless of the separate `tests` workflow's result — CI could be (and was) red and production would still redeploy. Changed the trigger from `push` to `workflow_run` (workflow: `tests`, `types: completed`), gated with `if: github.event.workflow_run.conclusion == 'success'`, so a failing `tests` run now blocks the deploy instead of racing it.
- **2026-08-01:** site-wide 500 (`Class "view" does not exist"`, root cause `Class "Laravel\Sanctum\Sanctum" not found`). `deploy.sh` decided whether to run `composer install` by checking `git diff HEAD@{1} HEAD` (the reflog) instead of the commit range actually just pulled — an unreliable check that skipped `composer install` on the deploy that added `laravel/sanctum`, leaving `vendor/laravel/sanctum` missing, which broke package discovery and cascaded into every service provider (including `view`) failing to register. Fixed by diffing `$OLD_COMMIT`..`$NEW_COMMIT` (captured directly around the `git reset --hard` in the deploy script) instead of the reflog, for both the composer.lock and package.json/package-lock.json checks.
- **2026-08-02:** a confirmed-correct PHP fix (a header-relocated Save button missing its `form` attribute) didn't take effect after deploying, because `deploy.sh` never restarted/reloaded PHP-FPM — only the Horizon queue worker (`supervisorctl restart`). With `opcache.validate_timestamps=0` (or just stale opcode caching in general), the FPM workers kept executing pre-deploy bytecode indefinitely for any changed PHP file, no matter how many times `git pull`/`composer install` ran. Made worse by discovering the server runs **both `php8.3-fpm` and `php8.4-fpm` simultaneously** — reloading only one is a coin flip as to whether it's the one nginx actually proxies to. Fixed by reloading *every* running `php*-fpm` systemd unit (not just the first found) right after the Horizon restart, before `php artisan up` — non-fatal if none are found, since server setups can vary.

## Current state — what's actually built
The MVP foundation is live in production; work now is depth and polish, not scaffolding.

**Domain model** (`app/Models`): `Listing` (accommodation/activity/restaurant/vehicle, with `vehicle_category` splitting self-drive vs guided tour), `RoomType`, `Partner`, `Inquiry`, `Trip`, `SavedPlan`, `Review`, `Region` (Namibia's 14 political regions) → `City` (incl. villages/settlements, see `SettlementType`) → listings, `Destination` (curated destination cards), `RouteTemplate` + `RouteTemplateStop` (curated classic routes Kaia adapts instead of inventing routes freeform), `EnrichmentJob`, `ApiClient`, `PartnerMessage`, `SupportMessage`, `TripFeedback`, `ReleaseNote`.

**Kaia** (`app/Services/Kaia`, `routes/kaia.php`, `config/kaia.php`): interview + itinerary generation. Both phases now run on **Haiku** — the itinerary call used to be Sonnet with a multi-round `search_listings` tool loop; the catalog is now fetched deterministically in PHP and handed to Claude in one single-shot forced-tool-call request, so round-trips are fixed at 1 and a fast model suffices. Driving times come from `OsrmDrivingTimeService` + the `city_driving_hours` table, not from model memory.

**Trip plan (Reiseplan)** — the flagship feature: `resources/js/components/home/ItinerarySection.vue` and friends (`TripMap`, `ItineraryLineItem`, `AlternativesPanel`, `RoomTypePicker`, `TripMeta`), saved/shared via `SavedPlan` tokens (`/trip/{token}`, no login required), PDF export. See `TRAVEL_PLAN.md`.

**Partner connectors** (`app/Connectors`, `app/Enums/ConnectorType`): ResConnect (ResRequest), NightsBridge, hopeCloud, NWR (concierge/manual), **Native** (NamibWay's own bookable inventory with real `RoomType` availability + soft holds), Wetu (content-only), Manual. `ConnectorFactory` resolves per partner; `ProcessInquiry` drives availability → reservation → confirmed/on-request.

**Content acquisition:** Python scrapers (`scripts/`, run via GitHub Actions) feed `data/scraped/`, imported via `providers:import` / the `namibway:scrape-*` commands. The enrichment pipeline (`app/Services/Enrichment`) then finds websites, extracts structured data with Claude, generates descriptions, sources photos via Google Places/OSM, and scores completeness. Partner claim invites (`ClaimInviteService`, `/claim/{token}`) let real owners take over a scraped listing.

**Ops:** nightly backups + `backup:monitor`; `namibway:fetch-partner-emails` polls the shared mailbox by POP3 every two minutes (never deletes; Roundcube reads the same box by IMAP); nightly coordinate backfill; currency conversion with cached exchange rates.

### Cost guards — do not remove
- `EnrichmentBudgetGuard` enforces daily USD caps (`config/enrichment.php`: `places_daily_budget_usd`, `ai_daily_budget_usd`). Built after a redundant-lookup bug burned **~$840 / ~123,500 Google Places calls in a single day** with nothing to stop it. A big one-off backfill may legitimately need more — raise the cap temporarily via env, don't disable it.
- Nightly enrichment (`listings:nightly-enrich`) is **commented out in `routes/console.php` since 2026-08-04** because Claude API costs ran too high. Re-enable with a smaller `nightly_batch_size` once understood.
- Kaia itself stays cheap (~$0.15–0.50 per completed planning session, both phases on Haiku) relative to commission per booking — cost pressure comes from the bulk enrichment pipeline, not from planning.

### Data-loss lesson
`namibway:backfill-listing-cities` once overwrote correct `city_id` values because a "Windhoek" hit in a free-text address field (many remote operators use a Windhoek postal address) beat the real location. The command is fixed, but re-running it does **not** repair already-corrupted rows — `restore-listing-city-ids.sh` surgically restores just that column from a backup. Treat any bulk backfill over existing non-null data as destructive: gate on "currently empty" or dry-run first.

## The core product mechanic — read before touching the booking flow
The central design problem: **turning an AI-generated plan into confirmed bookings without flooding partner owners with speculative requests.**

Status of the governance rules:
- ✅ **One active request pipeline per traveler** — enforced by email in `ListingController::storeInquiry` / `storeBatchInquiry` against `InquiryStatus::isActive()`; a batch shortlist request checks the gate once for the whole batch.
- ✅ **Real commitment signal before requests go out** — name, email, travel dates required (no payment).
- ✅ **Soft hold with expiry** — Native connector reservations set `hold_expires_at` and dispatch `ExpireNativeHoldJob`, which releases the hold and mails the guest if the partner doesn't respond in time (idempotent, status-guarded).
- ✅ **Low-effort partner response** — signed one-click confirm/cancel URLs (`routes/partner.php`) plus the same transition from the logged-in dashboard, both through `InquiryDecisionService`.
- ⬜ **Staged confirmations** — lock accommodation first, then layer in activities/restaurants once the route is fixed. Still not implemented; today's flow treats each inquiry independently.

## AI engine notes
- Claude is NOT the source of truth for hard logistics facts (driving distances, night-driving rules, fuel stops). These come from the maintained `city_driving_hours` data + OSRM, and route shape comes from `RouteTemplate` — don't trust model memory for specific Namibian geography.
- Model choices live in `config/kaia.php` and `config/enrichment.php` with the reasoning written out. Change them there, not inline.

## Business model (for context, not to build yet)
Revenue: commission on bookings (modeled minimal, ~5%, deliberately below OTA rates), government/tourism-sector funding, advertising (featured partner listings), after-sales/e-shop (Phase 2/3, deferred).

## Partner landscape
Systems common in the Southern African lodge market, all now represented by a connector: ResRequest (dominant PMS, free ResConnect API), NightsBridge (channel manager), HOPE Software / hopeCloud (Namibia-specific, Tourism Levy + NTB/HAN reporting), Wetu (guest-facing itineraries, useful as a content source). Namibia Wildlife Resorts (state-run camps in Etosha, Sossusvlei, Fish River Canyon) has a known-unreliable booking system — handled as a deliberate special case (`NwrConnector`, `InquiryStatus::NwrPending` = manual concierge check), and it's the likely source of the "marked full but actually available" problem that motivated this whole project. Connector credentials/behaviour still need validating against real partner accounts.

## MVP scope (5 workstreams) — status
1. **Technology & Platform Foundation** — ✅ Laravel/Inertia/Vue/Postgres live, auto-deploying, backed up, with iOS/Android shells.
2. **Core Engine** — ✅ Kaia interview → itinerary; ✅ request governance except staged confirmations (see above).
3. **Content Creation** — ✅ Filament admin + R2 media + scrapers + AI enrichment + partner claim flow. Ongoing: real photos with rights confirmation, pricing, room types.
4. **Interfaces / Partner Integration** — 🟡 all connectors implemented; none validated end-to-end against a live partner account yet. Public `/api/v1` + Scribe docs + partner API guide PDF exist for the other direction.
5. **After-Sales / Up-Sell** — 🟡 support/feedback endpoints + `TripFeedback` exist; the on-trip progress tracker is designed but not built (`TRAVEL_PLAN.md`). E-shop still deferred.

## UX reference
`namibia_travel_prototype.html` is the original vanilla-JS prototype and is now mostly ported into real Vue components (hero + live chat, itinerary variants, Explore rows + filters, after-sales cards). Still unported: the booking-request-queue animation that illustrated the one-active-request rule. Its branding ("Namibia Travel & Life") is obsolete — current branding is the tan compass mark on `#3b2418` brown (`public/images/pwa/icon-512.png`), which all app icons and splash screens derive from.

## Where to work next
Don't scaffold — pick up the backlog. Read `TRAVEL_PLAN.md` → "Known gaps / next up" first, then choose (or ask which is most urgent). The named open items: real per-room-type photos + availability wired into `RoomTypePicker`, the richer vehicle-type + daily-budget picker, the on-trip progress tracker, staged booking confirmations, and validating a connector against a real partner account. Add a dated entry under `TRAVEL_PLAN.md`'s Backlog when you finish something there.

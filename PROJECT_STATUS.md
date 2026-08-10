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

- **`Inquiry` is the booking record.** Its statuses are request-shaped, not stay-shaped:
  `pending`, `processing`, `on_request`, `nwr_pending`, `confirmed`, `cancelled`,
  `failed` (`App\Enums\InquiryStatus`). There is no arrival, in-house, checked-out or
  no-show state.
- **Availability is derived, never stored.** `App\Services\Booking\RoomAvailability`
  answers "how many units are free" as `total_units` minus the overlapping inquiries
  in `on_request` or `confirmed`. **There is no calendar table.** That query *is* the
  source of truth.
- **One inquiry is implicitly one unit.** The `inquiries` table has no quantity column,
  so "three rooms for the same party" cannot be expressed as one booking today.
- **`RoomType` carries a single flat `rate_per_night`** plus `total_units`, `max_adults`,
  `max_children` and a string `code`. No seasons, no weekday/weekend, no contracted
  versus rack rates.
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

The consequence: today's model can express *"a traveller asked for a room and a partner
said yes"*. It cannot express a rate that changes by season, a room blocked for
maintenance, a booking of three rooms, a walk-in guest, or anyone standing at a front
desk. That gap is workstream A.

---

## 3. Workstream A — a booking system lodges can operate

**Goal:** a booking system that lodge staff operate themselves, with NWR as the first
partner we try to connect. The flyer we hand them proposes a pilot on **one camp for one
season, running alongside what they use today** — that promise should shape the build.

### What is missing, concretely

Ordered so that each item depends only on the ones above it:

1. **A calendar table.** Per room type, per date: units available, rate, minimum stay,
   closed-to-arrival. Everything else here depends on it, and derived availability
   cannot express a block or a season. This is the first thing to build, and it changes
   `RoomAvailability` from the source of truth into a reader.
2. **Quantity per booking** — several rooms, possibly of different types, under one
   reservation and one guest.
3. **Blocking**: maintenance, owner use, a group hold that is not a guest booking.
4. **A stay lifecycle** beyond request states: due-in, in-house, checked-out, no-show,
   cancelled-late. This is what makes it operable at a desk rather than an inbox.
5. **Front-desk surfaces**: today's arrivals and departures, an occupancy view, capture
   of a walk-in or telephone booking, and room-level assignment if they assign real
   rooms rather than room types.
6. **Multi-property under one partner.** NWR is one partner with many camps. `Listing`
   already models that; the partner panel does not — it has no property switcher.
7. **Money**: what a stay costs, what was paid, what is owed. There is a `total_amount`
   on an inquiry and nothing else.

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

### Suggested first slice

The calendar table plus per-date rates and quantity, a partner-panel property switcher,
and an arrivals/departures view — with NWR modelled as one `Partner` and its camps as
`Listing` rows. That is enough to run one camp for one season without touching the
traveller-facing flow, which is what the flyer promises.

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

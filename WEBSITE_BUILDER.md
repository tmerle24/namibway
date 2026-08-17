# Business-Owner Website Builder — Concept Brief

**Status:** Two documents in one file. The next section is the commission for the content
editor, **built on 2026-08-12** and kept because its constraints still bind whatever is
built here next. Everything after it is the original brief from
the concept pass, kept for the constraints it sets, which still hold. That older half is
**not** a description of the system: slice 1 shipped 2026-08-12 and `PROJECT_STATUS.md` →
Workstream B is the live record, including where a decision here was later reversed
(booking on a customer's site is an enquiry plus a payment link the business sends, not a
checkout).

---

## Commission — the content editor (2026-08-12)

> **Built 2026-08-12.** `EditBlocksAction`, `App\Filament\Support\Sites\BlockForm` and
> `BlockEditorTest`, in both panels. The commission is kept because the constraints in it
> still bind whatever is built here next; `PROJECT_STATUS.md` → "Built 2026-08-12 — the
> content editor" records what was decided while building it, including the two things it
> deliberately does not do (pages are still not creatable, and pictures cannot be uploaded
> from the editor).

### Read first

- `CLAUDE.md` — the project rules. In particular: **everything that goes into the repository
  is English** (code, comments, commit messages, UI copy, both Filament panels).
- `PROJECT_STATUS.md` → Workstream B, "Next up, in the order it was asked for". The first
  item there is this commission.
- The rest of this file — the original brief. Historical, as above.

### What exists

- `Site`, `SitePage`, `SiteBlock`, `SiteImage`. The website owns its content; a listing is
  an import source at creation time and nothing else.
- Thirteen block types in `app/Sites/Blocks/`, registered in `App\Sites\BlockRegistry`. A
  type is a class and a line — never a migration, never a column. The payload is JSON,
  validated by `BlockDefinition::rules()`.
- The renderer: server-side Blade under `resources/views/sites/`, inlined CSS, no Vue.
- `App\Filament\Support\WebsiteTab`, with `EditHeroAction`, `EditSiteLogoAction`,
  `EditTypographyAction`, `EditCustomDomainAction` and `EditLegalTextAction` already on it.
  The pattern: `make()` returns the form action, `header()` the page action, and a shared
  `configure()` holds the behaviour — **one implementation serving both panels**.

### What is missing, and is the job

Only the frame is editable today. The content is not: the blocks are written once by
`sites:generate` and can then be changed by nobody. A typo in generated prose cannot be
corrected, and a business with no listing gets an empty frame that nothing can fill —
roughly half of them have no listing.

Build:

1. Blocks **added, reordered, switched on and off, and edited** from the Website tab.
2. A form per block type, carrying that type's fields.
3. Images chosen from the site's **own `SiteImage` rows** — never read from the listing.
   That independence is what makes "you keep your content" true rather than a slogan.
4. Pages created.
5. **The same actions in the partner panel**, as the same implementation. What differs is
   scope and permission, never the fields. The moment there is an admin version and a
   customer version of a piece of content, it is two products at one price.

### Already decided — do not relitigate

- **The Filament fields do not go into the block classes.** `App\Sites\Blocks\*` is domain
  code serving the public renderer; panel code does not belong there. Put them in a class
  in the Filament layer instead (`App\Filament\Support\Sites\BlockForm` or similar) that
  answers with the fields for a given type.
- **Drift is prevented by a test, not by a note in a file.** Every type in `BlockRegistry`
  must have a form, and every field name in a form must appear in that type's `rules()`.
  Same shape as `MoneyWritePathTest` or `AdminNavigationTest`, where a test enforces the
  rule rather than a sentence asking somebody to remember it.
- **Booking on a customer's site is not a checkout.** The guest sends an enquiry, the
  business answers through the existing confirm/decline mail and sends a payment link. No
  login, no session, no cross-domain token exchange on a tenant host. Nothing in this
  commission may move that.

### Constraints a change here can break

- **Validation goes through `BlockDefinition::rules()`.** Nothing writes a block without
  passing them, and the editor is not an exception.
- **No markup from outside** the rich-text path and its allow-list
  (`Listing::sanitizeRichText`). An owner who cannot introduce code cannot introduce a
  vulnerability into a page served under our own certificate.
- **The byte budget stands:** 60 KB of delivered HTML including inlined CSS and JavaScript,
  asserted by `SitePerformanceBudgetTest`.
- **Rebuild protection already exists.** `sites.imported.blocks` records what generation
  wrote; a block whose payload no longer matches is left alone by a later `sites:generate`.
  An edited block therefore counts as edited with no extra bookkeeping — nothing to build,
  but it must not be broken (`SiteGenerator`).
- **Panel navigation is alphabetical** and enforced. Do not add `$navigationSort` to
  anything inside a group.
- The block library is deliberately small. A new type is not what is being asked for here.

### How this is worked

Own branch; `composer ci:check` before pushing (eslint, prettier, vue-tsc, pint, phpstan,
`artisan test` — red CI means no deploy). Open a draft pull request and merge it once CI is
green. Add a dated entry under Workstream B in `PROJECT_STATUS.md` when it is done.

---

The original brief follows. The line below is its own opening and described the pass that
produced it.

**Status at the time of writing:** Concept phase. **No production code** is written in this
pass.

> **About this file.** This is the commissioning brief for Workstream B in
> `PROJECT_STATUS.md` — the website builder behind the N$ 399/month offer. It was written
> as a task assignment, and it is kept here verbatim in substance so the constraints it
> sets stay auditable against whatever gets built. Two adaptations were made when it moved
> into the repo: it was translated to English (project rule, `CLAUDE.md` → "Language"), and
> the deliverable path was changed from `docs/business-owner-website-builder.md` to
> `WEBSITE_BUILDER_CONCEPT.md`, because this repo keeps its documents at the root and has
> no `docs/` directory.

---

## 0. How this is worked (binding)

The brief is worked in a single pass — no waiting for intermediate approvals.

| Stage | Task | Result |
|---|---|---|
| 1 | Repository analysis | Inventory of what exists **directly in the reply**, compact, before the document |
| 2 | Concept document | `WEBSITE_BUILDER_CONCEPT.md` |
| 3 | Open points | List at the end of the reply: what needs a decision from me |

The stage 1 inventory is mandatory and comes **before** the document, so I can follow what
the concept builds on. It may be short — a table is enough.

Where a decision is missing: **make a reasoned assumption, mark it as an assumption, keep
working.** Do not block, do not ask and stop.

### Burden of evidence

Every statement about existing functionality must be backed up — with a file path, class
name, migration name or route. No guesses, no assumptions carried over from general
knowledge about Laravel projects.

Classification for every component:

* **Existing** — present, backed by a path
* **Reuse** — present and directly usable for the builder
* **Missing** — not findable in the repo (that is a valid and welcome answer)
* **New** — has to be built
* **Decision** — architectural decision required, present the options with trade-offs
* **Future** — deliberately outside the MVP

If something is not found: **Missing.** Not "probably present", not silently assumed.

---

## 1. Product idea

A tourism or local business should get its own small, responsive website with **ideally a
single click** — generated from the data of an existing listing where there is one, and
built from the same kit with empty blocks where there is not.

Target chain:

> Listing → Website → Bookability → Payment

with no manual technical setup and without the owner needing technical knowledge.

### Module architecture

The system consists of three modules with clearly different coupling:

| Module | Role | Coupling |
|---|---|---|
| **Listings** | Master data of the businesses, marketplace content | tight with Booking |
| **Booking** | Availability, prices, bookings, booking management | tight with Listings |
| **Websites (CMS)** | Customer websites, own content, presentation | **independent** — Listing only as an import source, Booking as an optional interface |

**Listings and Booking stay tightly coupled.** They are NamibWay's free core.

**The CMS is a standalone module with its own data.** It works without a listing and
without booking — both are optional suppliers, not prerequisites (section 5a). Where a
bookable listing exists, it embeds the booking system as a widget.

The decoupling is deliberate: the CMS should be able to evolve without touching listing or
booking logic, and owner changes to the website must affect neither the listing nor the
booking system.

Business types:

* Accommodation
* Restaurant
* Activity provider
* Car rental
* Tour operator
* other tourism provider

What comes out is a **mini website**, not a full-scale web presence builder. The owner
maintains it themselves through simple standard modules.

---

## 2. Scope and ambition

### What we are not building

**No free-form page builder. No drag-and-drop system.**

The obvious alternative was explicitly rejected as well: an automated WordPress install per
owner with the credentials handed over. Reasons: far too much functionality for the
purpose, a security risk, and permanent maintenance effort per instance. Instead we build
**our own small kit** — WordPress-like in principle, but radically reduced to what a
tourism provider actually needs.

### What we are building

A controlled system of a few high-quality standard modules, assembled automatically in a
sensible order depending on the business type.

The ambition is explicitly **not** "it works", but: the website looks professional and has
a wow effect without the owner having to do anything for it. They make no design decision,
they take care of nothing, the result is finished and beautiful.

We follow established patterns from hospitality, travel, tourism, restaurant, activity and
rental. No experimental designs — the patterns exist, users know them, we implement them
cleanly and to a high standard.

### Expected standard scope of a website

As orientation for template and modules — the scope a finished owner website should offer
without anyone doing anything:

* Sticky menu / navigation
* Hero area
* Marketing introduction
* Content blocks of image and text, alternating
* More / continue buttons
* Prices
* Book button and booking module (widget, see section 3)
* About us
* Contact
* Map / location
* Footer with imprint/legal, privacy and terms

Assess against the data model which of these blocks can be filled automatically and which
need input from the owner.

### Quality bar: agency level

The benchmark is not "usable website", but: **good enough that an owner wonders why other
providers charge a multiple for it.** Everything you find on a professional tourism website
in the market should be here without anyone doing anything.

From that follows a priority decision for the MVP: **one single template at agency level
beats five average ones.** The quality of the template is the product — not the number of
modules, not the feature scope of the CMS. Plan the effort accordingly.

Work out a compact design token system before building, and document it in the concept:

* **Colour:** 4–6 named hex values, derived from the context (Namibia, hospitality,
  desert/light/expanse) — not the first-best warm-sand-plus-terracotta default
* **Typography:** at least two roles, one characterful display face used sparingly, one
  calm reading face. Typography carries the impression, not decoration
* **Layout:** grid, whitespace, image cropping — described, not just asserted
* **Signature:** the one recognisable element that makes these websites unmistakable

Webfonts count against the performance budget: at most two families, `font-display: swap`,
subsetting.

### Motion: wanted, but selective

Movement is explicitly wanted — it produces a considerable part of the impression. It just
has to be delivered cheaply.

**Wanted:**

* Sticky navigation with a state change on scroll
* Scroll reveals, staggered (IntersectionObserver, a few lines — no library)
* Hover and focus micro-interactions
* Slow image zoom or Ken Burns in the hero
* Soft transitions between states, an orchestrated loading sequence for the first view

**Not wanted, because expensive for little effect:**

* Video backgrounds
* WebGL, 3D, canvas effects
* Heavyweight animation or scroll libraries
* Uncompressed or oversized images

One orchestrated movement works better than many scattered effects. `prefers-reduced-motion`
is respected.

### Speed is part of the wow effect

The target environment is Namibia: weak mobile networks, expensive data. A page that stays
white for three seconds does not feel high quality, it feels broken. Therefore:

* Progressive enhancement: the page is fully readable without JavaScript, motion is the
  additional layer
* Hero image prioritised, everything else lazy loaded
* Responsive image sizes, modern formats, delivery over R2
* Define a **performance budget** — total weight of the first view, time to interactive on
  a slow connection — and justify the numbers
* Check every module against that budget before it goes into the template

---

## 3. Stage 1 — repository analysis

**Start with the existing documentation.** Read every Markdown file in the repository —
`README.md`, `CLAUDE.md`, anything under `docs/` and every other `.md` in the project. They
describe the current state, the architectural decisions taken, and the terms used in the
project. Adopt those terms, do not invent a parallel vocabulary.

Where the documentation deviates from the actual code: the code wins, note the deviation in
the concept.

Then analyse the repository and answer for each area: what exists, where does it live, what
state is it in?

* Listings / business data and their data model
* Business types — how are they modelled, how do they differ
* Booking logic
* Availability logic
* Pricing logic
* **Payment system** — see the premise check below
* User/owner management, authentication, permissions
* Image handling (upload, storage, variants, R2 connection)
* Reviews
* Locations / geodata
* Existing APIs and services
* Frontend components and design system
* Filament admin structures

### State of booking — integration as a widget

The booking system is ready for the MVP. The website embeds it as a **widget**: a booking
form in the website frontend that pulls availability and prices directly from the booking
system and hands the booking over to it.

Booking data is explicitly **not** part of the CMS:

* **no** booking backend at website level
* **no** own booking table, no own booking status, no copy of availabilities or prices
* **no** booking management in the owner CMS — the owner manages bookings where they do
  today
* Confirmations, notifications and status changes stay with the booking system
* The booking is marked as originating from the website (attribution) — check whether the
  booking model already has a field for that

Availability and prices are read **live, always**. Even though texts and images in the CMS
are independent: booking-relevant values are never copied and never cached where a booking
with a wrong price could come out of it.

Analyse the concrete interface: which services/classes accept a booking, what request
format, what validation, what availability check runs before submission. Describe the
widget structure per business type in the concept, and the call chain behind it.

Also check what permanently links website and booking — presumably the listing ID. That
link stays in place even when the website content diverges from the listing.

Assign the scope clearly: listing and booking are free, the subscription pays for the
website layer on top.

### Payment boundary — parallel session

> **Updated 2026-08-12 — that parallel session has landed.** The payment system is no
> longer in motion: `app/Services/Payments` exists, `PAYMENTS.md` describes it, and the
> guide staff read is **Documentation → Payments Guide** in `/admin`. So the sentences
> below about "state in motion" no longer apply — read the real classes. What does still
> apply is the boundary itself: **the website builder does not change payment code.** It
> states what it needs and the money side provides it.
>
> Two things a builder session should know before writing that contract down. One-off
> booking payments are built and go through `PaymentGateway` and a `PaymentProvider`
> (`demo`, `dpo`, `paystack`); **recurring billing for the N$ 399 subscription is not** —
> that is a genuinely new requirement on the money side, not a configuration of the
> existing one, and DPO's recurring support is unverified. Second, every payment is
> recorded against a **reservation folio** today. A website subscription has no
> reservation, so either the ledger grows a second kind of thing to be paid or the
> subscription is invoiced outside it — an open design question, and the first one the
> contract has to answer.

**The payment system is being worked on at the same time in another session. Payment code
is neither written nor changed here.**

This session instead defines the **interface contract**: what the website builder needs
from the payment system, in what form, at which points in the flow. Concretely:

* what payload a website booking hands to payment
* which responses/states the builder must handle (success, failure, pending, refund)
* which callback/webhook points are needed
* what recurring billing for the subscription needs on top of one-off booking payments

Describe that as a requirement on the payment system, not as an implementation proposal for
it. You may read and reference existing payment classes — but not change them, and not
create migrations for them.

If you find payment-relevant files during the analysis: note them as **Existing**, with a
path, without assessing their completeness. The state there is in motion.

---

## 4. Assumptions and open points

Record every assumption made in its own section of the document — what was assumed, what it
rests on, what happens if it is wrong.

Open points that need a decision from me additionally go as a short list at the end of your
reply. Prioritised: what blocks the build, what can be clarified later.

---

## 5. Concept document

Target: `WEBSITE_BUILDER_CONCEPT.md`

Not all chapters matter equally. The following weighting is to be observed — depth where
decisions are made, brevity in the rest.

### Core chapters (detailed, with trade-offs and a recommendation)

1. **Multi-tenant architecture** — tenant isolation, permissions, caching, security
2. **URL strategy** — assess the options:
   * `business.namibway.com` (subdomain per business)
   * `namibway.com/business/business-name` (path)
   * later a custom domain `business-name.com`

   Criteria: effort, DNS/TLS handling, SEO, migratability later. Make a recommendation.
   **No separate deployment infrastructure per business** — all websites are served from
   the same platform.
3. **Data model** — standalone CMS tables, import from the listing, optional link for the
   booking widget. See section 5a.
4. **Booking form** — a capture layer on top of the existing NamibWay booking system (see
   section 3). Form structure per business type, availability display, service calls, error
   and success states in the frontend. No own booking logic, no own booking management.
5. **Payment interface** — requirements on the payment system being developed in parallel
   (see section 3). A contract, not an implementation.
6. **Subscription model** — see section 6 below
7. **Legal texts in the footer** — see section 5c
8. **Host role, security and safeguards** — see section 5d
9. **MVP scope** — see section 7 below

### Short chapters (each brief, no prose embellishment)

8. Product vision
9. User flow
10. Business types
11. Website modules
12. Template system
13. Owner CMS
14. API requirements
15. Frontend architecture
16. Security / permissions
17. SEO
18. Performance
19. Future extensions
20. Open questions

---

## 5a. Data ownership: own data, listing as an optional import source

**Decided.** Not up for assessment — only the implementation is to be worked out.

### Confirmed by sales, 2026-08-17 — two customer groups, and only one of them is tourism

The section below was written as a design premise. It is now what is actually being sold:
the co-founder is out selling websites, and the customers fall into two groups.

* **Tourism partners** — lodges, restaurants, operators who already have a **listing** on
  NamibWay. The website is a second surface on a business the platform already knows, and
  the listing is the import source described below.
* **Everyone else** — trades, workshops, craftsmen, shops, service businesses. **No listing,
  and there never will be one**: there is nothing for Kaia to put in an itinerary, and their
  customers are substantially **locals** rather than travellers. For them the website is the
  whole product, and NamibWay is their web agency rather than their booking channel.

The second group is the reason `sites.partner_id` exists and why a site must be attachable
to a **partner alone**. Two consequences worth stating plainly, because both have already
been got wrong once:

* **A partner-only site is not a degraded listing site.** It is the normal case for half the
  customers. Anything that assumes `Site::$sourceListing` is present is a bug, not an edge
  case — `inquiries.listing_id` was NOT NULL for exactly this reason and a shop's own website
  could receive no enquiry at all (see `PROJECT_STATUS.md` § 4, 2026-08-16).
* **Not every audience is a traveller.** Copy, template defaults and anything Kaia-shaped
  must not assume the reader is planning a trip. A plumber's website sells to the town it is
  in.

### Where this is heading — a business directory, noted 2026-08-17

Possible, not decided, and worth knowing about before the partner side is built any further:
a public directory of **all** partners and their products, on its own domain — the names in
play are **NamibWay.na** or **NamibBusiness.com**. Independent of tourism listings: the
plumber and the craft shop appear there on the same footing as a lodge, and the audience is
whoever is looking for a business, traveller or local.

It is not a small feature, and the reason to write it down now is that it needs things the
partner side does not have today. Nobody should build the missing pieces *for* it yet —
but nobody should make them harder to add either:

* **A partner has no public identity.** `Partner` is an account that owns listings and
  receives inquiries. It has no publish state, no description, no logo of its own, no
  address you could put on a directory card. Today all of that lives on the `Listing` or on
  the `Site`, both of which the non-tourism customer may not have.
* **There is no trade or category taxonomy.** `ListingType` classifies tourism-bookable
  things. A directory needs "plumber", "welder", "grocer" — a different axis, and not one to
  bolt onto `ListingType` (see `BOOKING_BEYOND_ROOMS.md` § 6 on why "which vertical am I
  serving?" is the wrong question to build around).
* **Products are scoped to a site, not to a partner.** `shop_products.site_id` is right for
  a website and wrong for a directory that wants to search across every partner's goods.
  Reaching them centrally means going through `sites`, which a partner-only customer has
  exactly one of — workable, but it is the join a directory would live on, so it should not
  get any harder.
* **A second public front end.** It is a third audience after namibway.com and the tenant
  sites, so it is a third host on the same app rather than a second application — the
  pattern `SiteResolver` already establishes.
* **The content-source ladder still applies.** Nothing sourced from a third-party directory
  becomes publishable because we put it in a directory of our own.

### Starting point: not every customer has a listing

The kit is also sold to businesses that **have no listing on NamibWay** and will never get
one — restaurants, workshops, shops, service providers with nothing tourism-bookable about
them. For them the website is the entire product.

From that it follows necessarily: **the listing cannot be the data foundation of the
website.** A website that reads its content live from a listing has no source for these
customers. Two separate data paths — one for partners, one for non-partners — would be
double the build and test effort for the same product.

### The model

**The website always has its own data.** Own tables, own content, own media. That holds
regardless of whether a listing exists.

A listing is an **optional import source at creation time**:

* **With a listing:** content is taken over once at creation — texts, images, address,
  location, contact, opening hours, facilities. After that it belongs to the website. Later
  listing changes do not propagate, later website changes never propagate back into the
  listing.
* **Without a listing:** the same kit, the same modules, starting with empty blocks and
  placeholders. No second system, no cut-down variant.

The import is a head start, not a dependency.

### Why not coupled

Besides the customer without a listing, two further reasons speak against it, to be recorded
in the concept:

* **Ownership.** The customer is promised that the website and its content belong to them.
  What is read live from someone else's record, and disappears when that record is deleted,
  does not belong to them. Check the exact wording in `marketing/README.md` — the flyer
  counts as a binding promise and must not be undercut by the architecture.
* **Content source ladder.** Content with source `directory` is not publishable. A website
  reading through a coupling would potentially make such content public. See its own section
  below.

### Booking module only where bookable

The booking widget (section 3) only appears where a bookable listing sits behind the
website. For all other customers the module falls away with no replacement — the website
works fully without it. Where it makes sense, a contact or enquiry form takes its place.

Availability and prices are read **live, always** from the booking system and never
imported into the website.

### Content source ladder on import — hard rule

Only **publishable sources** may be taken over from a listing on import. Content from
directory research (`directory`) is excluded — texts as well as images.

To be settled concretely and answered in the concept:

* How does the import check the source of every single field and every image before taking
  it over?
* What happens to fields whose source is not publishable — leave empty and mark in the CMS
  as "to be filled in"?
* How are **drafts** handled that are produced for prospecting, before a customer has
  agreed? Not indexable, not publicly linkable, clearly separated from published pages.

### To be settled and answered in the concept

* **Images:** the import creates its own media records with its own R2 key, so the website
  is independent and a deletion in the listing does not break it. Check the storage
  consequences and whether deduplication at object level is possible without giving up that
  independence.
* **Usage rights:** the normal case is uncritical — texts and images come from the customer,
  the ownership promise holds. Two exceptions have to be handled: (a) material produced by
  NamibWay (photography, editorial) that reaches a customer-owned website through the
  import — it should stay recognisable as such on import, with the extent of use and the
  behaviour on cancellation recorded as an open point; (b) material supplied by the customer
  that they hold no rights to — covered by the logged confirmation step before publication
  (5d).
* **Re-import:** should a later, explicitly triggered reconciliation with the listing be
  possible? Never automatically. Assess whether MVP or future.
* **Empty modules:** a module without content must not appear as an empty block — especially
  relevant when starting without a listing. Hide automatically, mark as incomplete in the
  CMS, or both.
* **Customer without a listing:** describe the creation flow for this case in full. It is
  not the edge case, it is an equally ranked main case.

---

## 5b. Decision: page structure

To be decided and recommended: **one-pager or several small subpages.**

Assess against:

* Loading behaviour on a bad connection (one-pager: everything at once; subpages: smaller
  individual load)
* SEO — separate URLs per topic vs. a single indexed page
* Operability in the CMS for a non-technical owner
* Extensibility: if the system starts as a one-pager, the move to subpages later has to be
  possible without a data migration

The obvious answer is a one-pager for the MVP with a data structure that allows subpages
later (modules already carry a page reference that is constant in the MVP). Check that and
recommend.

---

## 5c. Legal texts in the footer

Imprint/legal, privacy policy and terms belong to the standard scope of every website — and
they are the only part of the kit with liability consequences. Treat it accordingly
carefully.

**Principle: frame it, do not write it.** The system provides structure and placeholders and
fills them with owner data (company name, address, registration, contact, responsible
person). It generates no legal texts that could pass as advice. The content stays the
owner's responsibility — and that has to be visible in the CMS, not in the small print.

To be answered in the concept:

* What mandatory details does a Namibian tourism business need in the footer? Mark as an
  open question needing clarification, do not answer it legally yourself.
* **GDPR:** the guests are predominantly European. That makes the regulation relevant for
  website operation and booking data, regardless of where the owner is based. What follows
  from that technically — cookie/consent behaviour, analytics yes/no, data processing in the
  booking form, data processing agreement between NamibWay and the owner?
* Who is the data controller when NamibWay hosts the website and processes the booking, but
  the owner is the operator? Record as an open point — that is a question for a lawyer, not
  for the architecture.
* Analytics/tracking: if it is to exist in the MVP, it determines the consent concept. If
  not, a lot gets simpler. Assess and recommend.

---

## 5d. Role as host, and safeguards

NamibWay runs all owner websites on its own infrastructure and wants to position itself as a
**technical host**. The concept has to support that position technically — and be honest
where it does not hold.

### Where the host role is not cleanly given

Host privilege presupposes neutral storage of third-party content. That presupposition is
partly broken here:

* Template, layout and module structure come from NamibWay
* On seeding, content is taken over that was partly produced by NamibWay (photography,
  texts)
* Booking and payment run through NamibWay systems, not the owner's
* Automatically generated parts (e.g. footer structure, consent mechanics) are NamibWay
  products

For these parts NamibWay is not the host but the author. Work out clearly in the concept
**which parts of a website are attributable to whom** — that is the basis of any liability
boundary. Do not make the legal assessment yourself; mark it as needing clarification with a
lawyer.

### Technical safeguards (MVP)

The most effective protection is the limitedness of the system itself. Record that
explicitly in the concept:

* **The owner cannot introduce code.** No free HTML block, no `<script>`, no iframe, no
  third-party embed. Only structured fields in predefined modules
* Rich text only through a whitelist of allowed markup, sanitised server-side
* Uploads images only, with type, size and content checks; no arbitrary files
* A strict Content Security Policy for the delivered websites
* Tenant isolation at data level: no access by one owner to another's data, enforced at
  query level, not only in the UI
* Rate limits on the booking form and the contact form; spam protection without tracking
  services
* Change log: who published which content and when — the necessary basis for any later
  dispute

These points are MVP, not future. They are cheap as long as the restriction holds from the
start, and expensive to retrofit.

### Contractual flanking (requirements on the non-technical side)

Describe what the platform has to provide technically so that the contractual side can bite:

* The owner's assurance that uploaded content belongs to them — as a confirmation step
  before publication, logged
* A reporting route for complaints (abuse contact) and a procedure that can take a website
  or an element offline at short notice
* Suspension and termination rights represented technically — a transition into a suspended
  state, aligned with the subscription states from section 6
* Usage rights to NamibWay content that reaches the website on seeding: what may the owner
  do with it, what happens on cancellation

### Compliance check scripts (future, but think about them now)

Later automated checks of owner websites for data protection, security and copyright
problems are planned.

**Important limitation:** a check without a defined follow-up process is not a safeguard but
the opposite — whoever checks acquires knowledge, and knowledge creates obligations to act.
Check scripts therefore belong together with:

* a defined procedure for what happens on a finding (inform the owner, deadline, suspension)
* logging of the finding and the reaction
* clear responsibility for who handles the reports

For the MVP it is enough to prepare the data model for it: findings and reactions have to be
storable per website later. The scripts themselves are **future**. Record as an open point
without building it now.

---

## 6. Subscription model

Business model:

* Website creation: **free**
* Ongoing operation: **399 NAD / month** on subscription
* At least one month free, payment only starts at the end of the following month
* Cancellable at any time, no contract lock-in
* Transparent and fair pricing — a deliberate sales argument towards partners

### Requirements on the concept

The price must **not be hardcoded**. Store it configurably (config/DB), so changes, special
conditions and later tiering are possible. Also clarify: is 399 NAD inclusive or exclusive
of VAT — record as an open question if no VAT handling exists in the repo.

The lifecycle of a subscription is **deterministic state logic in Laravel**, not prose.
Present it as a **state diagram** (Mermaid) with explicit states and transitions, at least:

* Trial
* Active
* Payment failed
* Grace period
* Suspended
* Cancelled
* Reactivation

And answer per state: **what happens to the publicly reachable website?** Does it stay
online, is it redirected to a notice page, does it disappear? What happens to already
confirmed bookings when a subscription ends?

The subscription state logic itself belongs in the website builder context and can be
designed here. The **payment processing** behind it belongs to the payment system in the
parallel session — formulate it here only as a requirement, do not implement it.

---

## 7. MVP cut

The MVP should **not try to solve everything at once.**

What gets built first is the **smallest sensible vertical end-to-end flow**:

> Listing → Website → Booking → Payment

Propose concretely:

* **one** business type to start with (with a justification for why this one)
* **one** template — and that one at agency level, see section 2. Template quality is not
  final polish, it is the MVP core
* the minimum necessary set of modules
* what is deliberately left out, and in what order it gets added

Further business types and modules only come after that.

### Reference module list (to be assessed, not adopted)

Hero/header, image gallery, description, highlights, facilities, prices, rooms/units,
activities, opening hours, menu, vehicles, location/map, contact, social media, FAQ,
reviews, booking, call to action, footer.

Assess against the actual data model which modules can be filled at all. A module with no
data behind it does not belong in the MVP.

### Template structures as a starting point

| Business type | Structure |
|---|---|
| Accommodation | Hero → Images → Description → Rooms → Facilities → Location → Reviews → Booking |
| Activity | Hero → Images → Description → Highlights → Duration → Price → Availability → Booking |
| Restaurant | Hero → Images → Description → Opening hours → Menu → Location → Reservation |
| Car rental | Hero → Vehicles → Description → Prices → Conditions → Availability → Booking |

---

## 8. Owner CMS

A very simple backend. The owner should have to configure as little as possible.

**Content (website-owned):** title, description, images, contact information, opening hours,
facilities, social media, address and location. Imported at creation where a listing exists,
otherwise empty — website-owned afterwards in both cases.

**Who edits:** see section 8a.

**Website:** choose a template, logo, main image, activate/deactivate modules, change the
order of certain modules, possibly an accent colour within defined bounds.

**Not in the CMS:** prices, availabilities, units/vehicles, bookings. These come from the
booking widget and are maintained in the booking system. The CMS shows where that happens.

Check whether Filament is the right surface for this, or whether an own owner area in the
frontend makes more sense. Present as a **Decision**.

---

## 8a. Open: who edits the content

The flyer holds out the prospect that changes are done for the customer ("you write to us,
we change it"). That is an **agency model** and it differs from the customer maintaining
things themselves.

Both are doable with the same data structure — the difference is only who has access to the
editor. Check the exact promise in `marketing/README.md` and align the MVP with it.

The obvious order, to be assessed and justified:

1. **Admin editor first** — Charnette or support maintain customer content. Covers the
   flyer's promise, is considerably less UI work, and the quality of the websites stays
   controlled.
2. **Customer editor later** — its own decision, its own cut, once it becomes clear what
   customers actually want to change themselves.

Important for the data model: both routes write into the same fields. There must be no
separate "admin version" and "customer version" of a piece of content. Only permissions and
surface differ.

Record which variant the MVP builds, and justify it from the flyer — not from technical
convenience.

---

## 9. One-click flow

1. Starting point: an existing listing **or** a customer without a listing
2. Click on **"Create Website"**
3. The system creates the website — with a listing, imported from its data; without a
   listing, with empty blocks and placeholders
4. The template is chosen automatically from the business type; without a listing the type
   is asked for at creation
5. The website gets a URL/subdomain
6. The owner can review and edit the content
7. The website can be published
8. Booking and payment are integrated automatically, where available for the business type

Creation should take only a few seconds. Describe what has to happen synchronously and what
belongs in the queue (Redis/Horizon).

---

## 10. Performance & responsive

Target environment Namibia — this is not a nice-to-have, it determines the architecture:

* Mobile first
* Slow connections, partly weak mobile networks
* Small data volumes
* Optimised images (sizes, formats, lazy loading, R2 delivery)
* Fast loading as a design requirement, not an optimisation afterwards

Professionally usable on smartphone, tablet and desktop.

Describe the caching strategy for publicly delivered websites — including invalidation when
the owner changes content.

---

## 11. Ground rule

Existing functionality is reused. No unnecessary duplication of logic.

We are building a **clean, simple and scalable foundation** — not accidentally a huge page
builder.

# Website package — one ZIP, one finished website

A website package is how a customer website is built **outside** the panel — by hand or with
Claude Code working from a folder of the customer's material — and brought in with one upload:
`/admin` → Content → **Import website** (`App\Filament\Pages\SitePackageImport`).

The import always runs twice: **Check** shows which partner, listing and website the package
matched and every field it would change, and writes nothing; **Import** checks the same file
again and writes it. Code: `App\Sites\Import\SitePackage` (reads the ZIP),
`SitePackageImporter` (plan/apply). Tests: `tests/Feature/Sites/SitePackageImportTest.php`.

## The ZIP

```
epima.zip
├── site.json
├── listings.csv               (optional — the listings for the platform)
├── listings/                  (optional — one folder per listing, named in photo_folder)
│   └── Namibia Top 3/          (matched to the row's photo_folder)
│       ├── cover-dunes.jpg
│       └── zebras.jpg
└── media/                     (any folder layout — files are found by name)
    ├── lion-reflection.jpg
    └── lion-on-the-road.mp4
```

- Pictures: `jpg`, `jpeg`, `png`, `webp`. Videos: `mp4`, `webm`, `mov`. Other files are ignored.
- File names must be unique across the whole ZIP.
- **Upload limit 12 MB** (Livewire). Shrink photos to ~1600 px on the long side at quality ~80
  (≈200 KB each) and re-encode clips (`ffmpeg -vf scale=-2:720 -crf 28 -movflags +faststart`)
  before zipping.
- Files land on R2 under the site's prefix: `sites/{slug}/{file}` and `sites/{slug}/videos/{file}`,
  with the name slugged. Re-importing overwrites the same keys, so a second run adds nothing twice.

## site.json

```json
{
  "version": 1,
  "partner": { "name": "…", "email": "…", "phone": "…", "address": "…",
               "latitude": -20.46, "longitude": 16.65, "website": "…",
               "social_links": { "facebook": "…", "tiktok": "…" },
               "short_description": "…", "bio": "…" },
  "listing": { "slug": "…", "type": "vehicle", "vehicle_category": "guided_tour",
               "name": "…", "contact_email": "…", "phone": "…", "address": "…",
               "short_description": "…", "description": "…" },
  "site":    { "slug": "…", "name": "…", "business_type": "tour_operator", "accent": "copper",
               "contact_email": "…", "contact_phone": "…", "whatsapp": "…", "address": "…",
               "latitude": -20.46, "longitude": 16.65, "social_links": { … },
               "title": "…", "meta_description": "…",
               "logo": "logo.png", "logo_hero_height": 120, "logo_compact_height": 52,
               "logo_shadow": "shadow", "edition": "enterprise" },
  "images":  [ { "file": "lion-reflection.jpg", "alt": "A lion drinking at an Etosha waterhole" } ],
  "blocks":  [
    { "type": "hero",    "data": { "image": "lion-reflection.jpg", "video": "hero-loop.mp4",
                                   "video_layout": "card", "headline": "…" } },
    { "type": "stats",   "data": { "items": [ { "value": 14, "unit": "days", "label": "…" } ],
                                   "ticker": ["Etosha", "Sossusvlei"] } },
    { "type": "offers",  "data": { "items": [ { "title": "…", "image": "cheetah.jpg" } ] } },
    { "type": "gallery", "data": { "images": ["a.jpg", "b.jpg"] } },
    { "type": "video",   "data": { "items": [ { "video": "clip.mp4", "poster": "clip.jpg" } ] } },
    { "type": "location" },
    { "type": "footer" }
  ],
  "pages":   [
    { "slug": "tours/namibia-top-3", "title": "…", "meta_description": "…",
      "nav_label": "Namibia Top 3", "show_in_nav": false,
      "blocks": [ { "type": "hero", "data": { "image": "dunes.jpg", "headline": "Namibia Top 3" } },
                  { "type": "itinerary", "data": { "items": [ … ] } },
                  { "type": "enquiry", "data": { "form_type": "tour_request", "listing_slug": "namibia-top-3" } } ] }
  ]
}
```

`logo` is a file in the ZIP — a transparent PNG cut to the mark's own shape sits best over the
hero photograph. It hangs from the top of the bar at `logo_hero_height` (32–300 px, capped at
112 px on a phone) and shrinks to `logo_compact_height` (24–120 px) once the page scrolls.
`logo_shadow`: `glow` (white, the default — for a dark logo), `shadow` (dark, for a colourful
mark or a badge) or `none`. `action_buttons` says where the enquiry, WhatsApp, call, map and
custom buttons sit (`App\Sites\ActionButtons`) — e.g. the enquiry button in the bar from the
first screen: `{ "enquiry": { "places": ["menu.desktop", "hero.desktop", "footer.phone"] } }`.

Every key is optional except `version`. The keys inside `partner`, `listing` and `site` are the
model's own column names; anything not listed above is ignored.

`edition`: `standard` (the default) or `enterprise` - the showcase design with a full-screen
opening, larger type and motion, same bands (PROJECT_STATUS.md § 4, 2026-09-17). A hero `video`
is a muted loop behind the headline, with `image` as its poster; `video_layout: "card"` is for a
portrait phone clip (full screen on a phone, a card beside the headline on a wide screen). Keep
it to 10-20 seconds and about 2 MB (`ffmpeg ... -an -crf 30 -movflags +faststart`).

**Subpages** (`pages`): created or updated by `slug`, never deleted. A slug may be a path
(`tours/namibia-top-3`) but not an address the site answers itself (`card`, `shop`, `order`,
`about`, the legal pages). `show_in_nav: false` keeps a page out of the menu; an offer card
reaches it with `"page_slug": "tours/namibia-top-3"` (button text: `page_button_label` on the
block). A subpage may carry the contact form with its tour chosen (`listing_slug` on the enquiry
block); every form on the site must ask for the same `form_type`. From the shell, for a ZIP over
the upload limit: `php artisan sites:import-package package.zip` (check) and `--apply`
(import). To preview locally without touching the bucket, run with
`CLOUDFLARE_R2_LOCAL_ROOT=storage/app/r2-local` (launch config `sites-local`).

A **tour request** form (`"form_type": "tour_request"` on the enquiry block) lists the
partner's own published listings with a fixed length (`duration_minutes`) — so the tours from
`listings.csv` appear in it on their own. The visitor picks one and a start date; the end date
is the tour's, and the request is recorded against that listing. Give each offer card the
tour's slug (`"listing_slug": "namibia-top-3"`) and its button opens the form with that tour
chosen; a card without one chooses "private / tailor-made", which asks for an end date.

**Blocks** take the same payload the editor writes (`App\Sites\Blocks\*::rules()`), with file
names where the block stores ids: `image` → `image_id`, `images` → `image_ids`, and per item
`image` → `image_id`, `poster` → `poster_image_id`, `video` → `key`. A block with only a `type`
keeps whatever it already has and is just placed at that position. `"enabled": false` switches
a band off. Payloads are validated in the check, so a bad one is reported, not half-written.

## listings.csv — the platform listings in the same ZIP

A package may carry the business's listings for namibway.com beside its website.
`listings.csv` is **the listings sheet**, exactly as Content → Import listings takes it (columns
in `App\Services\ImportExport\ListingSheet`: `id`, `name`, `type`, `vehicle_category`, `city`,
`address`, `coordinates`, `short_description`, `description`, `price_from`, `currency`,
`duration_minutes`, `website`, `email`, `contact_person`, `phone`, `photo_folder`, `photo_credit`,
`published`, `accepts_inquiries`, `slug`), and it is handed to that importer unchanged — one sheet
definition, one set of rules, one write path into `listings`.

Its rules apply here too: an **empty cell leaves the field alone**, and naming a `photo_folder`
**replaces** that listing's photographs.

**Give every row a `slug`.** In a sheet a person types, `id` is the only automatic update key —
a name matching an existing listing is reported with the id to type in, so nothing is silently
overwritten. A package is not typed: it is written by machine and describes one customer, so it
resolves the `id` itself from the slug before the sheet reaches the importer, and re-importing
updates the same listings instead of stopping. Only the package's **own partner's** listings are
resolved; a slug belonging to another business stays a reported collision. Listings the package
creates are attached to that partner, which the sheet itself has no column for. The folders it
names live under `listings/` in the same ZIP (`cover*` becomes the main image, the rest the
gallery); those files are not part of the website's own picture list and are not reported as
unused.

Two things the package adds on top: the listing rows are checked in the same dry run as the
website, and **a bad row stops the whole import** — a ZIP is imported as one thing, so a broken
sheet must not leave half a customer written. Leave `published` at `no` unless the listing is
ready to be live; publishing is a decision, not an import step.

## Matching — what is created, what is updated

1. **Website:** `site.slug` if it exists. Otherwise the website of the matched listing, then of
   the matched partner. Otherwise a new one is generated (`SiteGenerator`), from the listing if
   there is one, else partner-only — which then needs `site.business_type`.
2. **Partner:** the matched website's partner, else a partner with `partner.email`, else one with
   exactly `partner.name` (two with that name is an error). Otherwise created from `partner`.
3. **Listing** (only if the package has a `listing` section): the website's source listing, else
   `listing.slug`, else the partner's only listing, else the partner's listing with that name.
   Otherwise created, **unpublished**, starting from the partner's facts (name, email, phone,
   address, coordinates, website, social links) — `listing.type` is then required.

## What an import never does

- **Delete.** An empty or missing field is left alone. Pictures already on the site stay. A band
  the package does not name keeps its content and moves below the ones it does.
- **Publish.** A new listing is created unpublished and a website keeps its status; publishing
  stays the explicit step it is in the Website tab.
- **Invent content.** A package carries what the business supplied. Draft copy written to show a
  prospect is fine on a draft site, but prices and facts must be theirs. A placeholder guest
  quote goes in with `"sample": true` — it shows a "Sample" tag and blocks publishing until it is
  replaced (`TestimonialsBlock`, `PublishGate`).

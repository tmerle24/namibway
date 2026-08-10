# Marketing material

English-language print material for the three things we sell: listings on the travel
platform, cheap websites for Namibian businesses, and custom software — the last of
which currently means a booking system.

## The flyers

| Flyer | Audience | Source |
|---|---|---|
| Partners | Lodges, camps, guides and restaurants we want listed on NamibWay | `flyer-partners-a4.html` |
| Websites | Namibian business owners with no website, for the co-founder to prospect with | `flyer-websites-a4.html` |
| Booking system | Namibia Wildlife Resorts — a leave-behind for a meeting, not a counter hand-out | `flyer-booking-system-a4.html` |

Each builds two PDFs into `out/`:

- `*-print.pdf` — 216 × 303 mm, that is A4 plus 3 mm bleed on all four sides, no crop
  marks. This is the file for a print shop. The brown bands and the amber call to
  action run into the bleed, so a small trim variance never leaves a white sliver.
- `*-screen.pdf` — plain A4, for email and the office printer.

Colours are RGB. Most print shops convert to CMYK themselves; if yours insists on a
CMYK file, send them the print PDF and ask them to convert — the palette is muted
enough that the shift is small, but check a proof before a large run.

## Getting hold of the PDFs

The admin panel has them under **Documentation → Marketing material**, so nobody has
to be sent a file over WhatsApp or clone the repo to print something. That page lists
whatever `config/marketing.php` names and serves the files straight out of `out/`;
adding a piece means adding an entry there as well as building it.

The PDFs are committed, so a deploy ships whatever is in the repo — if you rebuild
them, commit the result or the admin panel keeps handing out the old ones.

## Building

```
node marketing/build-flyer.mjs            # all three
node marketing/build-flyer.mjs websites   # just the one whose filename matches
```

No npm dependencies — it drives Chromium's `--print-to-pdf`, which honours the CSS
`@page` size, and rewrites that one rule per variant.

**A page that overflows is clipped silently**, because `.page` is `overflow: hidden`.
After changing copy, check that each page's `scrollHeight` still equals its box height
rather than trusting how the PDF looks in a viewer. The websites flyer already carries
tighter spacing in its own `<style>` for exactly this reason.

Spots marked `EDIT:` in each file: the phone line in the contact strip (off everywhere,
because there is no number that is answered yet), an optional hero photograph, the
commission wording on the partner flyer, the monthly price on the websites flyer, and
the organisation name on the booking-system flyer.

## Other files

| File | What it is |
|---|---|
| `partner-outreach-copy.md` | Copy for the partner line: the flyer text plus an A5 hand-out, an A6 leave-behind card, a phone talk track and an objection FAQ |
| `flyer-base.css` | Shared print styles. All three flyers link it, so they stay one design |
| `assets/` | QR code for namibway.com and the compass mark recoloured for the brown and sand grounds |
| `claude-designer-prompt.md` | Ready-to-paste prompt if you would rather design a piece elsewhere, plus which brand files to attach and why the domain alone is not enough |

## Claims we may make

All of these are things the platform actually does today:

- A conversational planner (Kaia) that produces one bookable itinerary covering
  accommodation, activities, restaurants, vehicle and routing.
- Driving times and route shape come from maintained road data (OSRM plus a curated
  driving-hours dataset and curated route templates), not from a language model's
  guess about Namibian geography.
- Free listing, free to keep; commission only on confirmed bookings.
- One active booking request per traveller, enforced server-side, gated on an account
  plus name, email and firm travel dates.
- One-click confirm/decline from a signed email link, no login required.
- Soft holds that expire automatically and notify the guest, so inventory is never
  blocked indefinitely.
- Optional connectors: ResConnect (ResRequest), NightsBridge, hopeCloud, Wetu for
  content, or plain email with no setup at all.
- Partner dashboard for description, photos, rates and incoming requests.
- Platform languages: English, German, Dutch, French, Spanish.
- iOS and Android apps plus the web platform.
- Listing removal within 24 hours on request.

## Claims we may **not** make

- **Traveller or booking volume of any kind.** No user counts, no "thousands of
  trips planned", no growth figures. The platform is live but pre-launch on the
  partner side.
- **Named live partners, testimonials, logos or awards.** No partner is connected
  yet, so there is nobody to quote and no logo we have the right to print.
- **A validated connector integration.** Every connector is written against
  documented behaviour and has never run against a real partner account. Say "we can
  connect to", not "we are integrated with".
- **A commission percentage** — until that decision is signed off. The internal
  outreach handbook instructs staff not to quote rates on the phone, so a number on
  the flyer would contradict what our own people say. See the decision note in
  `partner-outreach-copy.md` §2.
- **Anything about a named competitor's reliability.** The availability problems that
  motivated this platform are real, but naming a state operator or a portal in print
  is a fight we do not need.

## Claims specific to the other two flyers

**Websites.** We can set a business up on Google Maps and a Google Business Profile.
We cannot promise where it ranks in search, and no flyer may imply it. The monthly
price is a proposal until someone signs it off; it appears twice in
`flyer-websites-a4.html` and both have to move together.

**Booking system.** The list on the back is deliberately limited to what is running in
production today. Two lines must not drift: no connector has ever been validated
against a live partner account, so "adapters designed for" must never become
"integrated with"; and no partner is connected, so there are no uptime, volume or
customer figures to quote. Nothing in that flyer criticises the system the recipient
runs today — the argument is the outcome, not their current supplier. Check the exact
registered name and preferred short form of the organisation before printing.

## Photography

Printed material may only use photographs we have the rights to. Scraped and
directory-sourced imagery is internal reference and is not publishable — the same
rule the platform enforces in code via `App\Enums\ContentSource`. Leave the photo
slots empty until rights-cleared images exist.

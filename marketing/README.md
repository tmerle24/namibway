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

## Brand marks

### The "Built in Namibia" seal

Round stamp — heavy double ring, **BUILT IN NAMIBIA** across the top, **NAMIBWAY**
along the foot, star separators, the compass mark in the middle.

| File | Use |
|---|---|
| `assets/built-in-namibia-seal.svg` | light grounds (paper, sand) — vector, use this for print |
| `assets/built-in-namibia-seal-light.svg` | the brown ground — vector |
| `assets/built-in-namibia-seal-1024.png` | transparent PNG for Canva, Word, social, signatures |
| `assets/built-in-namibia-seal-512.png` | the same, smaller |
| `assets/built-in-namibia-seal-light-1024.png` / `-512.png` | transparent PNGs of the light colourway |

Regenerate the two SVGs with `node marketing/build-seal.mjs`. The compass is lifted
out of the brand mark at build time rather than redrawn, so the seal cannot drift out
of step with the logo — change the logo, re-run, done. The PNGs are rasterised from
the SVGs; redo them if the seal changes.

This is meant to be reused, not redrawn: flyers, quotes, proposals, email signatures,
the website footer, invoices.

- Keep it circular. Do not stretch it, recolour it outside the two files, or set it on
  a busy photograph — it reads as a stamp only if it looks stamped.
- It stays legible down to about 20 mm across. Below that, use the plain compass.
- Put the light colourway on dark grounds; the dark one on anything pale.

**On the wording.** It says *built*, not *made*, for two reasons. "Made in Namibia" is
country-of-origin phrasing that reads as manufactured goods, and this is software.
More practically, it is close to the wording of the local buy-local campaign — a round
stamp saying "Made in Namibia" can be read as a certification we have not applied for,
which is a claim we should not make by accident. If we ever want that association, the
honest route is to join the scheme and use their mark, not to imitate it. The phrase
lives in one constant at the top of `build-seal.mjs` if this is ever revisited.

### The landscape illustration

`assets/namibia-scene.svg` — sunset sky, ridge layers and a camel thorn, used behind
the phone mock-up on the websites flyer.

It is **drawn, not photographed**, and that is a stopgap, not a preference. We have no
photograph whose rights are established: the images under `public/images/explore` are
demo assets of unknown provenance and are 900 px wide, which is too small for print
anyway. The flyer marks the slot `EDIT: hero photo` — swapping in a real photograph is
a one-line change once we own one.

### Logos

In `public/images/` — these are the app's own files, not copies, so they cannot fall
out of date:

| File | Use on |
|---|---|
| `namibway-logo-dark.png` | light grounds (paper, sand) |
| `namibway-logo-light.png` | dark grounds (the brown band) |
| `namibway-icon-amber.png` | the compass mark alone, large |
| `namibway-icon-amber-inline.svg` | the compass mark alone, vector |
| `pwa/icon-512.png` | the app icon, tan compass on brown |

`assets/compass-on-brown.svg` and `assets/compass-on-sand.svg` are the same mark
recoloured for those two grounds, used as the small ornament in the flyer strips.

Anyone who needs the logo — a co-founder building something in Canva, a print shop —
should be sent these files rather than a screenshot. A wordmark typed out in whatever
bold font is to hand is not the logo, and it is what makes material look homemade.

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

## What the live demo actually shows

`php artisan booking:demo-tenant <listing-slug>` puts a prospect's own lodge into the
system before the meeting, and prints a sign-in link to hand over. **What it shows is
real software on made-up data — not a mock-up, and not a pilot.** Say it that way.

**If the lodge has no room types on file — today that is all of them — the demo invents
three.** The command tells you when it has done that. Say so in the room: the room names
and the rates are examples, not their prices. Entering their real room types on the
listing makes the next rebuild use those instead, and that is worth doing before a
meeting that matters.

Demonstrable, because it works:

- An occupancy calendar for one property: room types down, nights across, stays and
  blocks as bars, units free and the rate on every night, sold-out and off-sale as
  visibly different things.
- An arrivals and departures board for one date, printable.
- Entering a walk-in or telephone booking, including several room types at once, with
  the price built from the calendar night by night and an override that is recorded.
- A booking being refused because a night is short — and the refusal naming the room
  type and the date.
- Moving a guest through the day: due in, in house, checked out, no show; cancelling,
  with the rooms going back on sale.
- Taking rooms off sale for maintenance or owner use, editing that, and releasing it.
- Setting rates and restrictions across a season in one go, weekends only if you like.
- Several properties under one partner, switched from the topbar.

**Added 2026-08-12 — the money side.** This section used to say payments, invoices and a
folio did not exist. They do now, and a demo tenant shows them with money already on some
of its stays:

- A folio on a stay: nights, what was added on top, what has been paid, what is left.
- Recording a payment by any method, a refund, and correcting a line that should not have
  been entered — with both lines left standing.
- An outstanding column on the arrivals board and an unpaid list.
- Issuing an invoice: numbered, gapless per property, VAT and tourism levy on their own
  lines, and a credit note as the only way to correct one.
- The whole online payment flow end to end — pay, decline, abandon, refund — on the demo
  provider, which needs no account and no internet. **Every page of it says it is a
  demonstration.**

The commercial framing for that is the three settlement models, and staff should read
**Documentation → Payments Guide** in the admin panel before pitching them. Two things
there are not decided and must not be promised: exactly when we count commission as
earned, and payment terms under the agency model.

Not demonstrable, and must not be implied:

- **A connection to anything.** No channel sync, no iCal, no PMS link. The demo tenant
  has no connector at all, deliberately.
- **Housekeeping or tax reporting.** Neither exists, and there is no button that hints
  at it.
- **A live card payment.** The payment flow *is* demonstrable — see below — but there is
  no merchant account, so nothing you show moves real money. Say "real software on
  made-up data" here too.
- **Room-level assignment.** The system holds room types and quantities, never a named
  room. A lodge that assigns physical rooms needs that built.
- **Anything about the prospect's own live data.** The demo is a copy. It carries none of
  their contact details and cannot write back to their listing — worth saying out loud in
  the room, because it is the first thing an operator worries about.

Every screen in a demo tenant carries a striped "Demonstration" banner, and it survives
printing and screenshots. Do not remove it for a nicer-looking slide.

## Photography

Printed material may only use photographs we have the rights to. Scraped and
directory-sourced imagery is internal reference and is not publishable — the same
rule the platform enforces in code via `App\Enums\ContentSource`. Leave the photo
slots empty until rights-cleared images exist.

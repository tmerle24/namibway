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

## The concept papers

A flyer argues; a concept paper explains. These are the multi-page A4 documents for
somebody who has already said "tell me how it actually works" — the co-founder, a
partner weighing us up, an investor. Same design system as the flyers, same build,
same claims discipline; they are just longer and carry diagrams.

**Hand over the overview first.** The other three go one level down into a single
product line and are written to be read on their own, in any order.

| Paper | What it covers | Source |
|---|---|---|
| The whole picture | Three lines on one platform and why that is one company; the loop where each line sells the next; where the money comes from; what runs, what is built but unproven, what is only decided | `concept-overview.html` |
| Kaia trip planning & listings | How a plan is made and why the model is never the source of a fact; the account line; the one-active-request rule; the content-source ladder | `concept-kaia-trip-planning.html` |
| Booking & payment | The hourglass, the occupancy calendar, how a night is priced, the folio and the gapless invoice, the three settlement models | `concept-booking-payment.html` |
| Websites | Why generated rather than hand-built, why the website owns its own content, the one-click flow, what an owner cannot do | `concept-websites.html` |

Two conventions run through all four and are not decoration:

- **Every capability carries a status chip** — `Running` (in production), `Built`
  (built and tested, never run against a live partner or real money) or `Designed`
  (decided, not built). Promoting a chip means the thing it describes changed. This
  is what makes the papers safe to hand to somebody who will check them.
- **Each ends on a page saying what we do not claim**, drawn from "Claims we may not
  make" below. That page is the most persuasive one in the pack and it is the first
  thing that will be cut by somebody who has not read this paragraph. Do not.

They cross-reference each other by name, so renaming one means editing the other three.
The monthly website price appears in the overview as well as in the websites paper and
the websites flyer — five places in total, and they have to move together.

## Building and checking

Each piece builds two PDFs into `out/`:

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

### Building

```
node marketing/build-flyer.mjs            # everything, flyers and papers
node marketing/build-flyer.mjs websites   # just the ones whose filename matches
```

No npm dependencies — it drives Chromium's `--print-to-pdf`, which honours the CSS
`@page` size, and rewrites that one rule per variant. The script is still called
`build-flyer.mjs` because renaming it would break every note that says how to rebuild
a flyer.

### Checking

```
node marketing/check-pages.mjs            # everything
node marketing/check-pages.mjs concept    # just the ones whose filename matches
```

**Run this after any copy change, before rebuilding the PDFs.** It exits non-zero and
names the page. It checks three things, all of which fail silently otherwise: nothing
ends past the trim edge, nothing has more content than its own box, and no image is
drawn off its own aspect ratio. Both variants, because they are not the same test —
the screen variant is 6 mm shorter and therefore tighter vertically, the print variant
has 3 mm less width on each side.

It reports the slack left on each page. **Leave a few millimetres rather than fitting
exactly**: font substitution on another machine moves this.

The snippet below is what this replaces, and it is kept because it explains half the
problem. **It is not sufficient on its own** — see the note after it.

**A page that overflows is clipped silently**, because `.page` is `overflow: hidden`.
Check it after changing copy — and do not use the page's own `scrollHeight`, which
lies here: `.page` is a flex column, so a back page that was clipping 9 mm still
reported `scrollHeight === clientHeight`. What has to be measured is whether the
page's direct children add up to more than the page:

```js
[...document.querySelectorAll('.page')].flatMap((p) =>
    [...p.children]
        .filter((c) => c.scrollHeight - c.clientHeight > 2)
        .map((c) => `${c.className}: ${(c.scrollHeight - c.clientHeight) / 3.7795} mm cut off`),
); // empty array = nothing is being clipped
```

Per *child*, not per page: `.page` is a flex column, so an overlong hero does not
push the call to action past the trim edge — it gets squeezed instead, and the page
reports a perfect fit while the hero quietly loses its last two lines. That is exactly
how the seal band below was nearly shipped with 20 mm of the hero missing.

**And that snippet still misses the failure the concept papers actually have.** On a
document page `.doc-body` is `flex: 1`, so its automatic minimum size is its own
content — overlong copy does not overflow that box at all, it pushes `.doc-foot` off
the bottom of the page, where `overflow: hidden` eats it without a trace. Every child
reports a perfect fit and the PDF is missing its page number and half a paragraph.
That is why `check-pages.mjs` measures *position* as well as overflow, and why it is
the thing to run rather than this snippet.

Leave a few millimetres of slack rather than fitting exactly: font substitution on
another machine moves this. All three flyers now carry tighter spacing in their own
`<style>` blocks, and each says in a comment what to re-measure.

Spots marked `EDIT:` in each file: the phone line in the contact strip (off everywhere,
because there is no number that is answered yet), an optional hero photograph, the
commission wording on the partner flyer, the monthly price on the websites flyer, and
the organisation name on the booking-system flyer.

## Brand marks

### The "Built in Namibia" seal

Round stamp — heavy double ring, **BUILT IN NAMIBIA** across the top, **NAMIBWAY**
along the foot, star separators, the compass mark in the middle.

There are two versions of it, and the printed material uses the second.

| File | Use |
|---|---|
| `assets/built-in-namibia-stamp-1024.png` | **the one on the flyers** — inked, distressed, light grounds |
| `assets/built-in-namibia-stamp-light-1024.png` | the same on the brown ground |
| `assets/built-in-namibia-stamp-512.png` / `-light-512.png` | the same, smaller, for Canva, Word, signatures |
| `assets/built-in-namibia-seal.svg` | the clean geometric version — vector, for anything that needs to scale |
| `assets/built-in-namibia-seal-light.svg` | the brown ground — vector |
| `assets/built-in-namibia-seal-1024.png` / `-512.png` and the `-light-` pair | transparent PNGs of the vector version |

Regenerate the two SVGs with `node marketing/build-seal.mjs`. The compass is lifted
out of the brand mark at build time rather than redrawn, so that version cannot drift
out of step with the logo — change the logo, re-run, done. Those PNGs are rasterised
from the SVGs; redo them if the seal changes.

**The stamp is the exception to that, deliberately.** It is a raster, drawn rather
than generated, because the ink texture is what makes it read as stamped and that
does not survive being built out of the vector mark. So it *can* drift: if the compass
in the logo ever changes, the stamp has to be redrawn, and nothing will warn you. It
is kept at 1024 px, which is about 1000 dpi at the 24 mm it prints at.

The source image had a cream background rather than transparency. The alpha channel is
cut from the ink itself, so it composites on any ground:

```
convert stamp-source.png \
  \( +clone -colorspace Gray -negate -level 8%,72% \) \
  -alpha off -compose CopyOpacity -composite \
  -fill '#3B2418' -colorize 100 \
  -fuzz 0% -trim +repage -resize '1024x1024!' \
  marketing/assets/built-in-namibia-stamp-1024.png
```

`-fill '#F3E7D3'` gives the light colourway; the final `!` forces the circle round,
which it was about 4% short of being.

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

### The lodge scene

`assets/lodge-scene.svg` — dunes at sunset, a camel thorn, and a lodge with its lamps
on. It is used **twice** on the websites flyer, and that is the point of it: full
width behind the hero, and again inside the phone mock-up, so the phone reads as
showing the place it is standing in.

It replaced a much thinner drawing (`namibia-scene.svg`, now deleted, in the history)
that was rejected on 2026-08-12 for coming out an illegible brown smear at A4. Three
things fixed it and are worth not undoing:

- **Tonal range.** The foreground goes almost black. A drawing that lives entirely in
  the middle of the range disappears under the hero's overlay and prints as mud.
- **Flat fills, not gradients, on the ground.** Chrome's print-to-PDF renders a
  gradient on those paths three shades too light and flattens the shape it is filling,
  while a flat fill in the same place comes out exact. The sky is one gradient and it
  does render — so test rather than assume, and test in Chrome: ImageMagick's own SVG
  renderer drops the sky entirely and shows black.
- **One line per `d`.** The same paths split across several lines came back with a
  straight edge where a curve should be.

**It is still a drawing, and a photograph should replace it.** We own no image whose
rights are established: the files under `public/images/explore` are demo assets of
unknown provenance and 900 px wide, too small for print. Swapping in a real
photograph of a lodge in its landscape is two lines in `flyer-websites-a4.html`
(`--hero-photo` and the `<img>` inside the phone), and both must point at the same
picture. What is needed first is a picture we may use: one the co-founder or a partner
took and has agreed we may print, or a stock licence bought for print. A partner's
photograph on our own marketing is not covered by them being listed — that is a
separate yes.

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
| `flyer-base.css` | Shared print styles. Every piece links it, so they stay one design |
| `document-base.css` | Layered on top of `flyer-base.css` for the concept papers only — cover, running head, page numbers, explanation boxes, status chips, feature grids and the inline-SVG diagram grammar. It repeats nothing from the base file, deliberately |
| `check-pages.mjs` | The clipping and distortion check described above |
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

**Added 2026-08-12.** The booking system and the money side used to be things we
described as coming. They run, so they may be sold — the list below is the same kind
of claim as the ones above, not a roadmap:

- An occupancy calendar per property: unit types down, nights across, units free and
  the rate on every night, sold-out and off-sale visibly different.
- Rates, seasons and stay restrictions written across a whole season in one pass.
- Bookings taken at the desk — walk-in or telephone, several unit types at once,
  priced from the calendar night by night, with a recorded override.
- An arrivals and departures board for a date, printable, with what is outstanding.
- Rooms taken off sale for maintenance or owner use, and released again.
- A guest record per property, with a comment log, and the same for a stay.
- A folio on every stay: nights, extras, paid, outstanding.
- Payments and refunds recorded by any method, corrected by reversal — both lines
  kept — and never edited.
- Numbered invoices, gapless per property, VAT and tourism levy on their own lines,
  credit notes as the only correction.
- An online payment flow, built for a gateway that operates in Namibia and settles in
  Namibian dollars. Say **built**, not **live** — see the claims we may not make.
- Several properties under one login.
- For a website customer who lets rooms: live availability and prices on their own
  site, with the guest finishing the booking on namibway.com.

The commercial framing of the money side is the three settlement models, and staff
should read **Documentation → Payments Guide** in the admin panel before pitching
them.

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
- **A price for the booking system**, for the same reason: there isn't one yet. The
  material offers to quote and names no figure.
- **That we take card payments today.** The whole flow is built and demonstrable, but
  no merchant account has ever settled a real transaction. "Built to settle in
  Namibian dollars" is the claim; "we accept cards" is not, and neither is anything
  about when a partner gets paid out — payouts and partner statements are not built.
- **A live customer website.** The website builder generates real sites on real
  domains with real certificates, but nobody is paying for one yet, so there is no
  customer to name, screenshot or link to. The phone mock-up on the websites flyer is
  drawn for exactly this reason.
- **Anything about a named competitor's reliability.** The availability problems that
  motivated this platform are real, but naming a state operator or a portal in print
  is a fight we do not need.

## Claims specific to the other two flyers

**Websites.** We can set a business up on Google Maps and a Google Business Profile.
We cannot promise where it ranks in search, and no flyer may imply it. The monthly
price is a proposal until someone signs it off; it appears twice in
`flyer-websites-a4.html` and both have to move together. The availability line on the
back is bounded too: a site really can show live availability and prices, but the
guest finishes the booking on namibway.com, so "book through NamibWay" stays in that
sentence.

**Booking system.** The list on the back is deliberately limited to what is running in
production today — which since 2026-08-12 is a great deal more than it was, and the
length of that list is the argument the flyer makes. Three lines must not drift: no
connector has ever been validated against a live partner account, so "adapters
designed for" must never become "integrated with"; no partner is connected, so there
are no uptime, volume or customer figures to quote; and no merchant account has
settled a transaction, so "built to settle in Namibian dollars" must never become "we
take card payments". Nothing in that flyer criticises the system the recipient runs
today — the argument is the outcome, not their current supplier. Check the exact
registered name and preferred short form of the organisation before printing.

The closing line offers to set one of their camps up and show it running. That is the
demo tenant below, and it runs on **example bookings** — the words are in the flyer,
and they stay there.

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

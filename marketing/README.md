# Marketing material — partner acquisition

English-language print material used to sign Namibian lodges, camps, activity
operators and restaurants as NamibWay partners.

| File | What it is |
|---|---|
| `partner-outreach-copy.md` | The approved copy: A4 flyer (front/back), A5 hand-out, A6 leave-behind card, phone talk track, objection FAQ |
| `flyer-a4.html` | The designed A4 flyer, front and back. Source of the PDFs |
| `build-flyer.mjs` | `node marketing/build-flyer.mjs` — renders the PDFs with headless Chromium, no npm dependencies |
| `assets/` | QR code for namibway.com and the compass mark recoloured for the brown and sand grounds |
| `out/` | The built PDFs |
| `claude-designer-prompt.md` | Ready-to-paste prompt if you would rather design a piece elsewhere, plus which brand files to attach and why the domain alone is not enough |

## The A4 flyer

`out/namibway-partner-flyer-a4-print.pdf` — 216 × 303 mm, that is A4 plus 3 mm bleed
on all four sides, no crop marks. This is the file for a print shop. The brown bands
and the amber call to action run into the bleed, so a small trim variance never leaves
a white sliver at the edge.

`out/namibway-partner-flyer-a4-screen.pdf` — plain A4, for email and the office
printer.

Both come from `flyer-a4.html`; edit that and re-run the build. Three things in it are
marked `EDIT:` — a phone line in the contact strip (off, because there is no number
that is answered yet), the commission wording, and an optional hero photograph.

Colours are RGB. Most print shops convert to CMYK themselves; if yours insists on a
CMYK file, send them the print PDF and ask them to convert — the palette is muted
enough that the shift is small, but check a proof before a large run.

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

## Photography

Printed material may only use photographs we have the rights to. Scraped and
directory-sourced imagery is internal reference and is not publishable — the same
rule the platform enforces in code via `App\Enums\ContentSource`. Leave the photo
slots empty until rights-cleared images exist.

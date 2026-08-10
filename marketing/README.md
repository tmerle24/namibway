# Marketing material — partner acquisition

English-language print material used to sign Namibian lodges, camps, activity
operators and restaurants as NamibWay partners.

| File | What it is |
|---|---|
| `partner-outreach-copy.md` | The approved copy: A4 flyer (front/back), A5 hand-out, A6 leave-behind card, phone talk track, objection FAQ |
| `claude-designer-prompt.md` | Ready-to-paste prompt for designing the pieces, plus which brand files to attach and why the domain alone is not enough |

Designed pieces (HTML/PDF) are not committed yet — add them under `marketing/out/`
when they exist, and keep this table current.

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

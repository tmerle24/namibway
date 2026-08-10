# Prompt for Claude (design surface) — NamibWay partner flyer

Copy the block under "The prompt" into Claude, attach the brand files listed under
"Attach these", and paste the copy from `partner-outreach-copy.md` where the prompt
says so.

---

## About passing the domain instead of the assets

Giving Claude `namibway.com` is worth doing as *context* — it can look at the live
site and match the feel. It is not a reliable way to hand over the brand, for two
reasons: a page fetch gives Claude a rendered description, not the logo file itself,
so the logo ends up redrawn from memory rather than placed; and the homepage does not
state the print palette, the safe area around the mark, or which logo variant to use
on a dark ground.

So: attach the files, state the hex codes, and mention the domain as a reference.

**Attach these** (all already in this repo):

| File | Use |
|---|---|
| `public/images/namibway-logo-dark.png` | Wordmark + compass, brown — for light/sand backgrounds |
| `public/images/namibway-logo-light.png` | Same lockup in white — for the brown ground only (it is invisible on white) |
| `public/images/namibway-icon-amber.png` | Compass mark alone, amber — accent, QR centre, back page |
| `public/images/pwa/icon-512.png` | The app icon: amber compass on brown. This is the canonical brand image |
| `public/images/og-image.png` | Shows how the brand is used with a photograph behind it |

Do not let the design regenerate, redraw, "clean up" or re-colour the compass mark —
it is a trademark in registration. It gets placed as the supplied file, nothing else.

---

## The prompt

> You are designing print material for **NamibWay**, an AI-assisted travel planning
> and booking platform for Namibia (namibway.com). I need a **partner acquisition
> flyer** — the piece we hand to lodge owners, camp managers, activity operators and
> restaurant owners in Namibia to get them to list their business with us.
>
> **Deliverables**
> 1. A4 flyer, double-sided (210 × 297 mm), designed for print: 3 mm bleed, keep all
>    text at least 12 mm from the trim edge, 300 dpi mindset for placed images.
> 2. An A5 single-sided hand-out (148 × 210 mm) that reuses the same design language
>    for trade fairs and counter drops.
> 3. An A6 leave-behind card (105 × 148 mm), double-sided, with a slot for one
>    property name and one QR code — this one gets personalised per business.
>
> Build each as a self-contained HTML page with print CSS (`@page` with the right size
> and bleed, `print-color-adjust: exact`) so it renders to PDF from the browser, and
> so it still reads correctly on screen. One file per piece.
>
> **Brand**
> - The logo files are attached. Place them; never redraw, re-colour or reconstruct
>   the compass mark. Clear space around the lockup: at least the height of the
>   compass mark on every side.
> - Palette — deep brown `#3B2418` (the brand ground), ink `#2A2317`, amber `#B45309`
>   for calls to action, muted gold `#B9812E` for eyebrows and rules, sand `#F5F0E3`
>   as the light background, `#E4D9BE` for hairlines and table borders, `#6B6350` for
>   secondary text. Use white space generously; this is not a discount leaflet.
> - Typography: a warm serif for headlines and a clean humanist sans for body text,
>   both from the standard web-safe/system set so the PDF renders anywhere. Body text
>   no smaller than 9.5 pt.
> - Feeling: magazine travel editorial — confident, quiet, lots of air. Namibia's
>   landscape is the visual reference (desert, dune ochre, deep shade). Not a
>   startup pitch deck, not a coupon.
>
> **Copy** — use the text below **verbatim**. Do not rewrite it, do not add taglines,
> statistics, testimonials, partner logos or awards, and do not invent numbers of any
> kind. If a line does not fit the layout, shorten the layout, not the sentence, and
> tell me what you had to squeeze.
>
> [PASTE SECTIONS 1–4 OF partner-outreach-copy.md HERE]
>
> **Layout direction**
> - Front of A4: brand ground at the top with the white logo lockup, the headline
>   large and in the serif, the sub-headline in a comfortable measure (roughly 60–70
>   characters), then the three benefit panels as equal columns on the sand
>   background. The call to action sits in a solid amber bar at the foot with the QR
>   code beside it.
> - Back of A4: "How it works" as four numbered steps across the page; the
>   comparison table with hairline rules only, no filled grid; costs and "where we
>   are today" as two short blocks; contact strip on the brand ground at the foot.
> - Leave space for one landscape photograph on the front (roughly the top third) and
>   one on the back. Mark them clearly as placeholders — I will supply rights-cleared
>   photographs of Namibia. Do not embed stock or generated imagery.
> - The QR code is a placeholder box with a caption saying which URL it must encode; I
>   will generate the real codes.
>
> **Hard constraints**
> - Spell the brand exactly **NamibWay** (one word, capital N and W) and the
>   assistant exactly **Kaia**. Never abbreviate either.
> - British English throughout.
> - No claims beyond the supplied copy — no traveller counts, no bookings figures,
>   no "trusted by" line, no fabricated partner names.
> - Every piece must be legible in plain black-and-white photocopy as well as in
>   colour; check that the amber call to action still reads when it goes grey.
>
> Start with the A4 front and back. Show me those first, then we will do the A5 and
> the card once the design language is settled.

---

## Follow-up prompts that are usually needed

- "Give me a version of the A4 with the commission rate named as **{{ x }}%** in the
  costs section, so I can compare it against the version that leaves it out."
- "Produce a German translation of all three pieces, same layout — this goes to
  German-speaking owners in Namibia, so keep the tone formal (Sie)."
- "Make an editable version where the property name, the claim URL and the phone
  number are single variables at the top of the file, so we can generate one card per
  property."
- "Show the A4 front at 25% scale next to the back so I can check they work as a
  pair."

---

## What to check before it goes to a printer

- Phone number and `partners@namibway.com` are real and monitored.
- The QR codes resolve — the personal claim link on the card is per-property and
  time-limited, so a card printed in bulk must point at the generic
  `namibway.com/claim` instead.
- The commission decision in `partner-outreach-copy.md` §2 has been made, and the
  phone script says the same thing as the flyer.
- Photographs are rights-cleared. Scraped and directory photography is internal
  reference only and must not appear in printed material.

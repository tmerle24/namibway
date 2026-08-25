# Brand — NamibWay and Kaia

What the two marks are, how they relate, and the handful of rules that keep them from
competing. Read this before drawing, placing or changing anything that carries either
name — a logo, an app icon, a PDF header, an email footer, a slide.

The exploration this came out of is a design canvas:
**https://claude.ai/code/artifact/4f57a3f5-7fba-4959-8e8b-eca788a92a1e** — it holds the
rejected directions with the reasons, which is the part that stops a settled question
from being reopened every few months.

## The rule that orders everything

**NamibWay signs the product. Kaia signs the conversation.**

NamibWay is the company a traveller books with; Kaia is who they talk to. So Kaia is an
*endorsed sub-brand*, never a second parent brand: she never appears without NamibWay
somewhere in the frame, and she never takes the top of a page from it. Everything below
follows from that sentence, and a change that contradicts it is a brand change, not a
design tweak.

## NamibWay

The mark is a **compass needle cut out of a filled disc** — tan `#E2AB6C` on brown
`#3B2418`, one weight, no outline, no gradient. The needle sits on the NE–SW diagonal
with a round eye at its centre; the eye is an island inside the cut-out, so the whole
mark is a single path.

Assets in `public/images/`: `namibway-icon-amber-inline.svg` (the mark as one path — use
this when you need a vector), `namibway-logo-dark.png` / `namibway-logo-light.png` (mark
plus wordmark), `namibway-icon-amber.png`, `namibway-compass-amber.png`,
`namibway-needle-white.png`, and `pwa/icon-512.png`, from which every app icon and splash
screen is derived (see `MOBILE_APPS.md`).

## Kaia

### The wordmark is the mark

Her name, set as **`kaia` — lowercase, Outfit SemiBold, letter-spacing −0.02em**.

The **i is drawn, not typed**: a plain rectangular stem with NamibWay's compass diamond
above it in place of the dot. That diamond is the *only* element borrowed from the parent
mark, and putting it there means the inheritance sits at the centre of her name instead
of standing next to it. Stem width ≈ 0.105 em, stem height ≈ 0.5 em (the x-height),
diamond ≈ 0.19 em, its lower point ≈ 0.53 em above the baseline.

The **endorsement lockup** is the piece that goes on the trip plan, the PDF and the mail
footer:

    kaia  │  a travel companion by NamibWay

**Colour.** The diamond carries the accent — tan `#E2AB6C` on brand ground, terracotta
`#C0533A` where Kaia's own accent is already in play — and the letters stay in the text
colour. Where there is only one ink (print, a partner's template, a fax from a lodge),
the diamond takes the text colour too and the mark still works.

**Small sizes.** Below roughly 15 px the diamond stops being a shape and reads as a dot.
That is fine and needs no special version: at that size nobody is reading a logo.

### The avatar is a k

A wordmark cannot sit in a 32 px circle, so the small slot — chat avatar, app icon,
favicon — gets **her initial `k`**, in the same face, as a filled tile or circle with the
letter in it.

Two colour cases, and they are the same figure with the roles swapped:

| Context | Ground | Letter |
| --- | --- | --- |
| Light surfaces (today's UI, light chat) | brown `#3B2418` | tan `#E2AB6C` |
| Dark surfaces (dark chat, dark headers) | sand `#D6C9B5` | brown `#3B2418` |

Circle in the chat, rounded tile as an app icon. It holds down to 16 px, which is the
size that decided it.

**Why the `k` and not something else** — all of these were drawn and compared at real
sizes before this was settled:

- **Not the compass.** It is NamibWay's, and a second compass in the same product is one
  too many.
- **Not a compass rose.** Too standard a travel motif, and at small sizes it reads as a
  Christmas star.
- **Not a speech bubble.** The shape works, but it ties Kaia to the chat window when she
  also signs the plan, the PDF and the on-trip help.
- **Not `ai`.** It turns her into a category rather than a person, and every AI product on
  the shelf already owns that square.
- **Not `ka`.** Reads as an abbreviation for something.

### What Kaia never does

- **No compass, in any form.** See above.
- **No second type family and no second palette.** She uses the colours already in the
  product; the only thing that is hers rather than NamibWay's is terracotta `#C0533A`.
- **No highlighting of the `ai` in her name.** Next section.

## The ai in the name

`kaia` contains **ai** in the middle, and its first three letters are **ka · i** — how a
German says **KI**. Both readings end on the same letter, and the diamond already sits on
that i.

It is a genuinely good find, and it stays out of the logo, for two reasons that were
tested rather than assumed:

- **Colouring `ai` splits the word** into `k / ai / a`, and the diamond drowns in the
  colour it is sitting on. Whatever marks the hinge should mark it once — and the diamond
  already does.
- **`ai` is the descriptive part of the name.** A figurative mark that highlights it is
  arguing that the name describes the product, which is the opposite of what makes a mark
  registrable. The clearance we hold is for *Kaia* as one invented word.

Where it belongs instead: **in copy, and in motion.** A logo has to hold still; a loading
or splash state does not — the word can arrive whole and the `ai` catch the light for a
moment. Say it in an "about Kaia" paragraph. Do not build it into the mark.

## Colours

| Hex | Role | Where it already lives |
| --- | --- | --- |
| `#3B2418` | Brand ground, brown | app icons, PWA, Kaia avatar ground |
| `#E2AB6C` | Tan accent — NamibWay's | compass mark, avatar letter on light |
| `#C0533A` | Terracotta — Kaia's own accent | `resources/css/kaia-home.css` |
| `#D6C9B5` | Sand | `kaia-home.css`; avatar ground on dark |
| `#8A7F68` | Muted text | `kaia-home.css` |
| `#2C2521` | Near-black text | `kaia-home.css` |
| `#FAF8F5` | Off-white page | `kaia-home.css` |

## Type

- **Instrument Sans** is the product's UI face, vendored into the repo — see
  `resources/fonts/README.md` and the 2026-08-24 deploy incident in `CLAUDE.md`.
- **Outfit SemiBold** is used for the Kaia wordmark **only**, and it ships as
  *outlined vector artwork, never as a webfont*. Adding a second family to the build
  would put a typeface on the critical path of a deploy for the sake of one word, which
  is exactly the dependency the font incident was about. If a surface needs the wordmark,
  it places the SVG.

## Trademark status

- **Word mark on "Kaia"** — cleared by a paid search, confirmed 2026-08-25. This is the
  broad protection.
- **Figurative mark** — a separate application. File it only once the artwork is final,
  so it is paid for once.
- **Names are used exactly**: NamibWay and Kaia, never abbreviated, renamed, or given an
  alternative for the assistant.

## Status — what exists and what does not

Settled: the wordmark, the endorsement lockup, the avatar letter and its two colour cases.

Not built yet, in order:

1. **Production SVGs** with the letters outlined — wordmark, endorsement lockup, avatar in
   both colour cases, app-icon tile.
2. **Wiring it in.** Kaia has *no visual presence in the product today* — she exists in
   the UI as a name in running text. The chat header, the trip plan, the plan PDF and the
   mail footer are the places that should carry the mark.

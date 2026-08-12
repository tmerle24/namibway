{{--
  The whole stylesheet for a customer website, inlined.

  Inlined because it is small enough to be, and because a separate file is a
  second round trip before the page can paint — on the connection the flyer
  promises to work on, that round trip costs more than the bytes do. There is no
  Tailwind build here and no shared asset with the travel platform: this page
  ships what it uses and nothing else.

  ## The token system

  Colour — a mineral base, not the warm sand and terracotta every Namibian
  tourism site already uses. Ink, slate, salt and bone are neutral on purpose:
  one template has to carry a lodge, a restaurant and a panel-beating workshop
  without any of them looking like a travel brochure, and without any of them
  looking like NamibWay, whose site this is not. The single accent is the only
  colour that changes between customers (config/sites.php), which is what makes
  one template look like theirs rather than like ours.

  Typography — two roles. A serif for display, set large and tight, doing all
  the character; the system sans for reading, doing none of it. Both are stacks
  rather than webfonts, which is a deliberate deferral: a subset WOFF2 behind
  --font-display is a one-line change once somebody has chosen a face and
  licensed it, and until then the typography costs zero bytes and paints on the
  first frame instead of swapping.

  Spacing — one scale, powers of roughly 1.5 from 4px, so vertical rhythm is a
  choice from a list rather than a number somebody typed.

  Signature — the numbered hairline rule. Every section is announced by a
  letterspaced micro-label with its number, above a hairline that runs the full
  width and carries a short accent tick at its left end. It is the same
  numbering the printed flyers use, so print and web read as one house, and it
  costs nothing but a border.
--}}
<style>
    :root {
        --ink: #16181C;
        --slate: #4A5058;
        --salt: #F7F6F3;
        --bone: #E4E0D8;
        --accent: {{ $accent }};

        --font-display: 'Iowan Old Style', 'Palatino Linotype', Palatino, Georgia, ui-serif, serif;
        --font-body: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;

        --s1: 4px; --s2: 8px; --s3: 12px; --s4: 20px; --s5: 32px;
        --s6: 48px; --s7: 72px; --s8: 112px;

        --measure: 64ch;
        --container: 1120px;
    }

    *, *::before, *::after { box-sizing: border-box; }

    html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }

    body {
        margin: 0;
        background: var(--salt);
        color: var(--ink);
        font-family: var(--font-body);
        font-size: 17px;
        line-height: 1.65;
        -webkit-font-smoothing: antialiased;
    }

    img { max-width: 100%; display: block; }

    a { color: inherit; }

    .wrap { width: 100%; max-width: var(--container); margin: 0 auto; padding: 0 var(--s4); }

    /* ---- Navigation -------------------------------------------------- */

    .nav {
        position: sticky; top: 0; z-index: 20;
        background: transparent;
        transition: background-color .3s ease, box-shadow .3s ease;
    }
    .nav__inner {
        display: flex; align-items: center; gap: var(--s4);
        min-height: 64px; padding: var(--s3) var(--s4);
        width: 100%; max-width: var(--container); margin: 0 auto;
    }
    .nav__name {
        font-family: var(--font-display);
        font-size: 20px; letter-spacing: -.01em;
        text-decoration: none; margin-right: auto;
        color: #fff; transition: color .3s ease;
    }
    .nav__links { display: none; gap: var(--s4); align-items: center; }
    .nav__links a {
        font-size: 13px; letter-spacing: .08em; text-transform: uppercase;
        text-decoration: none; color: rgba(255,255,255,.82);
        transition: color .2s ease;
    }
    .nav__links a:hover { color: #fff; }
    .nav.is-scrolled { background: var(--salt); box-shadow: 0 1px 0 var(--bone); }
    .nav.is-scrolled .nav__name { color: var(--ink); }
    .nav.is-scrolled .nav__links a { color: var(--slate); }
    .nav.is-scrolled .nav__links a:hover { color: var(--ink); }
    /* No hero: the bar sits on the page ground from the first pixel. */
    .nav--solid { background: var(--salt); box-shadow: 0 1px 0 var(--bone); }
    .nav--solid .nav__name { color: var(--ink); }
    .nav--solid .nav__links a { color: var(--slate); }

    /* The burger and its panel. Both are unhidden by the script, so a page
       without JavaScript shows neither a dead button nor an open list. */
    .nav__burger {
        display: flex; flex-direction: column; justify-content: center; gap: 5px;
        width: 40px; height: 40px; padding: 0 8px;
        background: none; border: 0; cursor: pointer;
    }
    .nav__burger span {
        display: block; height: 2px; border-radius: 2px; background: #fff;
        transition: transform .25s ease, opacity .2s ease, background-color .3s ease;
    }
    .nav.is-scrolled .nav__burger span, .nav--solid .nav__burger span { background: var(--ink); }
    .nav__burger[aria-expanded="true"] span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .nav__burger[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
    .nav__burger[aria-expanded="true"] span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    .nav__panel {
        display: grid; gap: var(--s1);
        background: var(--salt); box-shadow: 0 12px 24px rgba(0,0,0,.12);
        padding: var(--s3) var(--s4) var(--s4);
        border-top: 1px solid var(--bone);
    }
    .nav__panel[hidden] { display: none; }
    .nav__panel a {
        display: block; padding: var(--s3) 0;
        font-size: 15px; letter-spacing: .06em; text-transform: uppercase;
        text-decoration: none; color: var(--ink);
        border-bottom: 1px solid var(--bone);
    }
    .nav__panel a:last-child { border-bottom: 0; }

    /* Wide enough for the bar to carry the links itself: the burger and its
       panel go away entirely, whatever state the script left them in. */
    @media (min-width: 800px) {
        .nav__links { display: flex; }
        .nav__burger, .nav__panel { display: none !important; }
    }

    /* ---- Buttons ----------------------------------------------------- */

    .btn {
        display: inline-block; padding: 13px 26px;
        background: var(--accent); color: #fff;
        font-size: 14px; letter-spacing: .06em; text-transform: uppercase;
        text-decoration: none; border: 0; border-radius: 2px; cursor: pointer;
        font-family: var(--font-body);
        transition: transform .2s ease, filter .2s ease;
    }
    .btn:hover { filter: brightness(1.08); transform: translateY(-1px); }
    .btn--ghost {
        background: transparent; color: var(--ink);
        box-shadow: inset 0 0 0 1px var(--bone);
    }
    .btn--ghost:hover { box-shadow: inset 0 0 0 1px var(--accent); filter: none; }

    /* ---- Hero -------------------------------------------------------- */

    .hero { position: relative; margin-top: -64px; background: var(--ink); overflow: hidden; }
    .hero__media { position: absolute; inset: 0; }
    .hero__media img {
        width: 100%; height: 100%; object-fit: cover;
        animation: heroZoom 24s ease-out forwards;
    }
    .hero::after {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(180deg, rgba(10,11,13,.45) 0%, rgba(10,11,13,.15) 38%, rgba(10,11,13,.78) 100%);
    }
    .hero__body {
        position: relative; z-index: 2;
        min-height: min(84vh, 760px);
        display: flex; flex-direction: column; justify-content: flex-end;
        padding: calc(64px + var(--s7)) var(--s4) var(--s7);
        width: 100%; max-width: var(--container); margin: 0 auto;
        color: #fff;
    }
    .hero__eyebrow {
        font-size: 12px; letter-spacing: .18em; text-transform: uppercase;
        color: rgba(255,255,255,.78); margin: 0 0 var(--s3);
    }
    .hero h1 {
        font-family: var(--font-display); font-weight: 400;
        font-size: clamp(38px, 7vw, 76px); line-height: 1.02;
        letter-spacing: -.02em; margin: 0; max-width: 18ch;
        text-wrap: balance;
    }
    .hero__subline {
        margin: var(--s4) 0 0; max-width: 46ch;
        font-size: clamp(17px, 2.2vw, 20px); color: rgba(255,255,255,.88);
    }
    .hero__cta { margin-top: var(--s5); }

    /* Hero with no photograph.
       Not a rare case: plenty of listings hold no picture we are allowed to
       publish, and the first draft a prospect is shown is often one of them.
       Full height and bottom-aligned text is a composition built around an
       image — without one it is most of a screen of near-black with a line
       floating at the bottom, which reads as broken rather than as sparse.
       So: shorter, centred, and on a ground with some colour in it. */
    .hero--plain .hero__body {
        min-height: min(56vh, 460px);
        justify-content: center;
        padding-top: calc(64px + var(--s6));
        padding-bottom: var(--s6);
    }
    .hero--plain {
        background:
            radial-gradient(120% 90% at 15% 0%, color-mix(in srgb, var(--accent) 34%, transparent) 0%, transparent 60%),
            linear-gradient(160deg, #23262C 0%, var(--ink) 70%);
    }
    .hero--plain::after { display: none; }
    /* A rule under the name, so the band has a bottom edge rather than
       stopping. Cheap, and it ties the hero to the numbered rules below. */
    .hero--plain h1::after {
        content: ''; display: block; width: 64px; height: 2px;
        background: var(--accent); margin-top: var(--s5);
    }

    @keyframes heroZoom { from { transform: scale(1); } to { transform: scale(1.07); } }

    /* ---- Sections and the signature rule ------------------------------ */

    .section { padding: var(--s7) 0; }
    .section--tint { background: #fff; }

    .rule {
        display: flex; align-items: baseline; gap: var(--s3);
        border-top: 1px solid var(--bone);
        padding-top: var(--s3); margin-bottom: var(--s5);
        position: relative;
    }
    .rule::before {
        content: ''; position: absolute; top: -1px; left: 0;
        width: 34px; height: 2px; background: var(--accent);
    }
    .rule__num, .rule__label {
        font-size: 12px; letter-spacing: .16em; text-transform: uppercase;
        color: var(--slate);
    }
    .rule__num { color: var(--accent); font-variant-numeric: tabular-nums; }

    h2 {
        font-family: var(--font-display); font-weight: 400;
        font-size: clamp(28px, 4vw, 42px); line-height: 1.1;
        letter-spacing: -.015em; margin: 0 0 var(--s4); max-width: 22ch;
    }
    h3 { font-size: 18px; margin: 0 0 var(--s2); letter-spacing: -.005em; }

    .prose { max-width: var(--measure); color: var(--slate); }
    .prose p { margin: 0 0 var(--s4); }
    .prose p:last-child { margin-bottom: 0; }
    .prose a { color: var(--ink); text-decoration-color: var(--accent); text-underline-offset: 3px; }
    .prose ul, .prose ol { margin: 0 0 var(--s4); padding-left: 1.2em; }
    .prose li { margin-bottom: var(--s2); }

    /* Grid children default to min-width:auto, which lets a long word or a
       wide measure push a column past the viewport on a narrow phone. */
    .split > *, .cards > *, .channels > *, .foot__grid > * { min-width: 0; }

    .split { display: grid; gap: var(--s6); align-items: start; }
    @media (min-width: 860px) { .split { grid-template-columns: 1fr 1fr; gap: var(--s7); } }

    .figure img { width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; }

    /* ---- Highlights --------------------------------------------------- */

    .cards { display: grid; gap: var(--s5); }
    @media (min-width: 640px) { .cards { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 980px) { .cards--3 { grid-template-columns: repeat(3, 1fr); } }
    .card { border-top: 1px solid var(--bone); padding-top: var(--s4); }
    .card p { margin: 0; color: var(--slate); font-size: 16px; }

    /* ---- Gallery ------------------------------------------------------ */

    .grid-photos { display: grid; gap: var(--s3); grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 860px) { .grid-photos { grid-template-columns: repeat(3, 1fr); gap: var(--s4); } }
    .grid-photos img {
        width: 100%; height: 100%; aspect-ratio: 1 / 1; object-fit: cover;
        transition: transform .5s ease;
    }
    .grid-photos figure { margin: 0; overflow: hidden; }
    .grid-photos figure:hover img { transform: scale(1.04); }

    /* ---- Lists: hours, prices ----------------------------------------- */

    .rows { max-width: 720px; }
    .row {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: var(--s4); padding: var(--s3) 0; border-bottom: 1px solid var(--bone);
    }
    .row__main { min-width: 0; }
    .row__name { display: block; }
    .row__note { display: block; color: var(--slate); font-size: 15px; }
    .row__value {
        white-space: nowrap; font-variant-numeric: tabular-nums;
        color: var(--ink);
    }
    .note { margin-top: var(--s4); color: var(--slate); font-size: 15px; }

    /* ---- Booking ------------------------------------------------------ */

    .booking { background: #fff; border: 1px solid var(--bone); padding: var(--s5); }
    .booking__form { display: grid; gap: var(--s3); align-items: end; }
    @media (min-width: 720px) { .booking__form { grid-template-columns: repeat(4, 1fr) auto; gap: var(--s4); } }
    .field { display: grid; gap: 6px; }
    .field label {
        font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: var(--slate);
    }
    .field input, .field select {
        font: inherit; font-size: 16px; padding: 10px 12px;
        border: 1px solid var(--bone); border-radius: 2px; background: var(--salt);
        color: var(--ink); width: 100%;
    }
    .field input:focus, .field select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    .offers { margin-top: var(--s5); }
    .offer {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: var(--s4); padding: var(--s4) 0; border-top: 1px solid var(--bone);
    }
    .offer__meta { color: var(--slate); font-size: 15px; }
    .offer__price { text-align: right; white-space: nowrap; }
    .offer__price strong { font-size: 20px; font-variant-numeric: tabular-nums; }

    /* ---- Enquiry form -------------------------------------------------- */

    .enquiry { background: #fff; border: 1px solid var(--bone); padding: var(--s5); }
    .enquiry__form { display: grid; gap: var(--s4); }
    .enquiry__row { display: grid; gap: var(--s4); grid-template-columns: 1fr 1fr; }
    .enquiry__row > * { min-width: 0; }
    .enquiry__submit { width: 100%; }
    .enquiry__sent { font-size: 18px; }
    .enquiry__failed { margin-bottom: var(--s4); padding: var(--s3); border-left: 3px solid var(--accent); background: #fff; font-size: 15px; }
    /* Off-screen rather than display:none — some bots skip hidden inputs and
       fill in everything else, which would defeat the point of having one. */
    .enquiry__trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

    /* ---- Contact and footer ------------------------------------------- */

    .channels { display: grid; gap: var(--s4); }
    @media (min-width: 640px) { .channels { grid-template-columns: repeat(3, 1fr); } }
    .channel__label {
        font-size: 12px; letter-spacing: .14em; text-transform: uppercase;
        color: var(--slate); display: block; margin-bottom: 4px;
    }
    .channel a { text-decoration-color: var(--accent); text-underline-offset: 3px; }

    .foot { background: var(--ink); color: rgba(255,255,255,.72); padding: var(--s6) 0 var(--s5); }
    .foot__grid { display: grid; gap: var(--s5); }
    @media (min-width: 720px) { .foot__grid { grid-template-columns: 2fr 1fr; } }
    .foot__name { font-family: var(--font-display); font-size: 22px; color: #fff; margin: 0 0 var(--s2); }
    .foot p { margin: 0 0 var(--s2); font-size: 15px; }
    .foot a { color: rgba(255,255,255,.86); text-decoration-color: var(--accent); text-underline-offset: 3px; }
    .foot__legal {
        margin-top: var(--s5); padding-top: var(--s4);
        border-top: 1px solid rgba(255,255,255,.14);
        font-size: 13px; color: rgba(255,255,255,.5);
        display: flex; flex-wrap: wrap; gap: var(--s2) var(--s4);
    }
    /* Pushed to the end of the strip on a wide screen, so the business's own
       particulars read first and ours is a footnote. */
    .foot__powered { margin-left: auto; }

    /* ---- Legal pages --------------------------------------------------- */

    .wrap--narrow { max-width: 760px; }
    .section--legal { padding: var(--s7) 0 var(--s6); }
    .section--legal h1 { font-family: var(--font-display); font-size: 34px; margin: 0 0 var(--s5); }

    /* ---- Motion -------------------------------------------------------
       Enhancement only: every element below is fully visible without the
       script, and the script only ever adds the "in" class. A page that
       arrives with JavaScript blocked is a finished page, not a blank one. */

    .js .reveal { opacity: 0; transform: translateY(14px); }
    .js .reveal.in { opacity: 1; transform: none; transition: opacity .7s ease, transform .7s ease; }

    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior: auto; }
        .hero__media img { animation: none; }
        .js .reveal, .js .reveal.in { opacity: 1; transform: none; transition: none; }
        .btn:hover { transform: none; }
        .grid-photos figure:hover img { transform: none; }
    }
</style>

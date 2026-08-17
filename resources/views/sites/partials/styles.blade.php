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

        /* Chosen per site — see App\Sites\Typography for the list and for why
           every option is a stack the reader already has rather than a webfont. */
        --font-display: {!! \App\Sites\Typography::displayStack($site) !!};
        --font-body: {!! \App\Sites\Typography::bodyStack($site) !!};
        --font-brand: {!! \App\Sites\Typography::brandStack($site) !!};
        /* Mobile first: the wide-screen size is raised in a media query below,
           because a phone and a laptop cannot share one number here. */
        --brand-size: {{ \App\Sites\Typography::brandSizeMobile($site) }}px;

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
    /* Height, not min-height, and this is load-bearing rather than tidy.
       The hero is pulled up under this bar by exactly -64px, so anything that
       makes the bar taller — a name wrapping, a menu wrapping at tablet width —
       leaves a strip of page background above the photograph. Measured at 834px
       it was 90px tall and the strip was 26px. A fixed height makes that
       arithmetic true at every width instead of most of them; the rules below
       are what keep the content inside it, wrapping included.

       overflow:visible rather than hidden: the name is clamped by
       -webkit-line-clamp so it cannot exceed the bar, and hidden caused a
       two-step logo transition by snapping the clip mid-animation. */
    .nav__inner {
        display: flex; align-items: center; gap: var(--s4);
        /* 8px of vertical padding rather than 12: the bar's outside height is
           what the hero's negative margin cancels and must not change, but the
           room inside it is what a wrapped name has to live in. 48px fits two
           lines at any size this bar offers. */
        height: 64px; overflow: visible; padding: var(--s2) var(--s4);
        width: 100%; max-width: var(--container); margin: 0 auto;
    }
    .nav__name {
        /* Its own face and its own size, both settable per site. The default
           face is the body one, not the display one: an editorial serif at this
           size over an arbitrary photograph reads thin rather than
           characterful. The size is computed from the length of the name unless
           somebody has said otherwise. */
        font-family: var(--font-brand); font-weight: 700;
        font-size: var(--brand-size); letter-spacing: -.01em; line-height: 1.2;
        text-decoration: none; margin-right: auto;
        color: #fff; transition: color .3s ease, opacity .3s ease;
        /* Wraps to a second line rather than being cut. It used to be one line
           with an ellipsis, which failed twice over: on a phone there is only
           about 250px beside the burger and a real name needs 350, and
           `text-overflow` does nothing on a flex container anyway — so the name
           was severed mid-word with no ellipsis to say so, and
           "…Hunting Safari" became "…Hunting Safa".
           Two lines, clamped, and they fit inside the bar's 48px. */
        min-width: 0; white-space: normal; overflow: hidden;
        display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
    }
    /* A logo is one object and wants centring, not line boxes. */
    .nav__name--logo { display: flex; align-items: center; }
    /* Wide enough for the bar to be three times the width it is on a phone. */
    @media (min-width: 640px) { :root { --brand-size: {{ \App\Sites\Typography::brandSize($site) }}px; } }
    /* A name over a photograph needs its own shadow to stay readable — the
       hero's gradient is built for text much further down the frame. */
    .nav:not(.is-scrolled):not(.is-open):not(.nav--solid) .nav__name { text-shadow: 0 1px 12px rgba(0,0,0,.55); }
    /* An owner's mark is whatever shape it is, so it is bounded by height and
       left to find its own width. Hero state: prominent; scrolled: compact.
       Both height and filter transition so neither snaps when the bar turns solid. */
    .nav__logo {
        height: {{ $site->logo_compact_height ?? 36 }}px;
        width: auto; max-width: 200px; object-fit: contain; display: block;
        transition: height .3s ease, filter .3s ease;
    }
    /* nav__inner is overflow:visible (see above), so the logo can extend
       beyond the 64px bar in the hero state without any separate override. */
    .nav:not(.is-scrolled):not(.is-open):not(.nav--solid) .nav__logo {
        height: {{ $site->logo_hero_height ?? 56 }}px;
        /* White drop-shadow: makes the logo glow against any dark hero photo
           while respecting the logo's actual shape (transparency included). */
        filter: drop-shadow(0 2px 12px rgba(255,255,255,1));
    }
    /* nowrap: a menu item breaking across two lines was the other half of what
       made the bar taller than the hero's negative margin. */
    .nav__links { display: none; gap: var(--s4); align-items: center; flex-wrap: nowrap; }
    .nav__links a {
        font-size: 13px; letter-spacing: .08em; text-transform: uppercase;
        text-decoration: none; color: rgba(255,255,255,.82);
        white-space: nowrap; transition: color .2s ease;
    }
    .nav__links a:hover { color: #fff; }
    /* An open menu panel is a cream sheet directly under the bar, so the bar
       has to leave its over-a-photograph colours at the same moment — white on
       cream is not a state anybody should be able to reach. */
    .nav.is-scrolled, .nav.is-open { background: var(--salt); box-shadow: 0 1px 0 var(--bone); }
    /* Instant for the menu, unlike the scroll state. The panel appears in one
       frame, so a 300ms colour fade would leave the name white on cream for
       exactly as long as it takes to be noticed. */
    .nav.is-open, .nav.is-open .nav__name, .nav.is-open .nav__burger span { transition: none; }
    .nav.is-scrolled .nav__name, .nav.is-open .nav__name { color: var(--ink); }
    .nav.is-scrolled .nav__links a, .nav.is-open .nav__links a { color: var(--slate); }
    .nav.is-scrolled .nav__links a:hover, .nav.is-open .nav__links a:hover { color: var(--ink); }
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
    .nav.is-scrolled .nav__burger span,
    .nav.is-open .nav__burger span,
    .nav--solid .nav__burger span { background: var(--ink); }
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

    /* The one button in the bar. Smaller than a .btn elsewhere, because the
       bar's outside height is fixed at 64px and cannot grow — see above.
       Hidden on a phone, where there is only room for the name and the burger:
       below 640px the action bar at the foot of the page carries it instead,
       and carries it better.

       Two class names deep, and that is load-bearing rather than sloppy: this
       is a .btn as well, .btn is declared further down, and at equal
       specificity the later rule wins — so a single .nav__cta was overruled on
       every property it sets. */
    .nav .nav__cta { flex: none; padding: 9px 16px; font-size: 12px; }

    /* The enquiry button that arrives with the scroll.
       It is in the markup from the first byte and hidden, rather than inserted
       by the script: a button that appears by being unhidden costs one repaint,
       and one built in JavaScript costs a layout of the whole bar at the exact
       moment somebody is scrolling.

       640px is where the bar is wide enough to carry a button beside the
       burger. Below it the strip at the foot of the screen already has this
       button, and the bar has a name and a burger and no room for a third
       thing. */
    .nav .nav__cta--scroll { display: none; }
    @media (min-width: 640px) {
        .nav.is-scrolled .nav__cta--scroll { display: inline-block; }
        /* And the menu item it stands in for goes, so the same words are not in
           the bar twice. It is the last item (see the partial), so what the eye
           sees is that item turning into a button. */
        .nav.is-scrolled .nav__links .nav__link--action { display: none; }
    }

    /* Wide enough for the bar to carry the links itself: the burger and its
       panel go away entirely, whatever state the script left them in.
       1100, and the number is measured rather than chosen. Six menu items take
       about 600px, so at 1024 a 31-character name has 348px and only fits on
       one line at 17px — too small for a laptop. At 1100 it has 424px and fits
       at 20px. Below this the burger gives the name the whole bar. */
    @media (min-width: 1100px) {
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
    /* The secondary button over a photograph. .btn--ghost is ink on a bone
       hairline and disappears there; this one is the same idea in the other
       direction, with just enough fill to survive a bright sky behind it. */
    .btn--light {
        background: rgba(255,255,255,.14); color: #fff;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.6);
        -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
    }
    .btn--light:hover { background: rgba(255,255,255,.24); filter: none; }
    .btn__icon { height: 16px; width: 16px; vertical-align: -3px; margin-right: 7px; }
    /* WhatsApp: always visible on mobile, hidden on wide screens where a typed
       enquiry via email is the better fit. btn--desktop-only is the inverse. */
    .btn--whatsapp { background: #25D366; color: #fff; box-shadow: none; }
    .btn--whatsapp:hover { background: #1ebe5d; filter: none; }
    @media (min-width: 640px) { .btn--whatsapp { display: none; } }
    .btn--desktop-only { display: none; }
    @media (min-width: 640px) { .btn--desktop-only { display: inline-flex; } }

    /* ---- Product enquiry (desktop inline form on product detail page) -- */

    .product-enquiry { margin-top:var(--s4); padding:var(--s4); background:#fff; border:1px solid var(--bone); border-radius:4px; }
    .product-enquiry__form { display:grid; gap:var(--s3); }
    .product-enquiry__sent { margin:0; color:var(--slate); font-size:15px; }
    .product-enquiry__error { margin:0 0 var(--s3); padding:var(--s3); border-left:3px solid var(--accent); background:#fff; font-size:15px; }
    .product-enquiry__cancel { background:none; border:none; padding:0; font:inherit; font-size:14px; color:var(--slate); cursor:pointer; text-decoration:underline; }
    .product-enquiry__cancel:hover { color:var(--ink); }

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
    /* Wrap rather than shrink: two buttons at 375px are about 20px wider than
       the room they have, and a squeezed pair reads worse than a stacked one. */
    .hero__cta { margin-top: var(--s5); display: flex; flex-wrap: wrap; gap: var(--s3); }

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
    /* On one column the icon sits beside the words: stacked, it left a card's
       whole width empty to the right of a 24px mark. Once the cards are in
       columns there is no width to waste and the mark goes back on top. */
    .card {
        border-top: 1px solid var(--bone); padding-top: var(--s4);
        display: flex; align-items: baseline; gap: var(--s3);
    }
    .card p { margin: 0; color: var(--slate); font-size: 16px; }
    .card__body { min-width: 0; }
    @media (min-width: 640px) {
        .card { display: block; }
    }
    /* The one place the accent carries a shape rather than a line. Single
       colour on purpose: a set of line marks in the site's own accent reads as
       part of the design, and the same set in filled colour reads as a stock
       icon pack somebody dropped in. */
    .card__icon { color: var(--accent); height: 24px; flex: none; }
    @media (min-width: 640px) { .card__icon { margin-bottom: var(--s3); } }
    /* The gap a card gets when its own phrase matched nothing, so one unlit
       card in a lit row keeps its heading on the same line as the others. */
    .card__icon--none { visibility: hidden; }

    /* ---- Gallery ------------------------------------------------------ */

    .grid-photos { display: grid; gap: var(--s3); grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 860px) { .grid-photos { grid-template-columns: repeat(3, 1fr); gap: var(--s4); } }
    .grid-photos img {
        width: 100%; height: 100%; aspect-ratio: 1 / 1; object-fit: cover;
        transition: transform .5s ease;
    }
    .grid-photos figure { margin: 0; overflow: hidden; cursor: zoom-in; }
    .grid-photos figure:hover img { transform: scale(1.04); }
    .figure--lb { cursor: zoom-in; }

    /* ---- Shop ---------------------------------------------------------- */

    .shop-subbar {
        position: sticky; top: 64px; z-index: 90;
        background: var(--salt); border-bottom: 1px solid var(--bone);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 var(--s5); height: 40px; gap: var(--s4);
    }
    .shop-breadcrumb {
        display: flex; align-items: center; gap: 6px;
        font-size: 13px; color: var(--slate);
        overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
    }
    .shop-breadcrumb a { color: var(--slate); text-decoration: none; }
    .shop-breadcrumb a:hover { color: var(--ink); }
    .shop-breadcrumb span[aria-current] { color: var(--ink); }
    .shop-back {
        flex: none; font-size: 13px; color: var(--slate); text-decoration: none;
        white-space: nowrap;
    }
    .shop-back:hover { color: var(--ink); }

    .shop-search {
        position: relative; display: flex; align-items: center;
        margin-bottom: var(--s4); max-width: 480px;
    }
    .shop-search__input {
        width: 100%; font: inherit; font-size: 16px;
        padding: 10px 72px 10px var(--s3);
        border: 1px solid var(--bone); border-radius: 6px;
        background: var(--salt); color: var(--ink);
    }
    .shop-search__input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    .shop-search__btn {
        position: absolute; right: 36px; top: 50%; transform: translateY(-50%);
        background: none; border: none; padding: 4px; cursor: pointer; color: var(--slate);
        display: flex; align-items: center;
    }
    .shop-search__btn svg { width: 18px; height: 18px; }
    .shop-search__btn:hover { color: var(--ink); }
    .shop-search__clear {
        position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
        font-size: 14px; color: var(--slate); text-decoration: none; line-height: 1;
    }
    .shop-search__clear:hover { color: var(--ink); }

    .shop-filters {
        display: flex; flex-wrap: wrap; gap: var(--s2); margin-bottom: var(--s5);
        align-items: center;
    }
    .shop-filter {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px var(--s3); border-radius: 9999px;
        border: 1px solid var(--bone); background: transparent;
        font-size: 14px; color: var(--ink); text-decoration: none; cursor: pointer;
        transition: background .15s, border-color .15s;
    }
    .shop-filter:hover,
    .shop-filter--active { background: var(--accent); border-color: var(--accent); color: #fff; }
    .shop-sort {
        margin-left: auto;
        font-size: 14px; color: var(--slate); background: transparent;
        border: 1px solid var(--bone); border-radius: 6px;
        padding: 6px var(--s3); cursor: pointer;
    }
    .shop-pager {
        display: flex; align-items: center; justify-content: center;
        gap: var(--s2); margin-top: var(--s6); flex-wrap: wrap;
    }
    .shop-pager__btn, .shop-pager__num {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 36px; height: 36px; padding: 0 var(--s2);
        border-radius: 4px; border: 1px solid var(--bone);
        font-size: 15px; color: var(--ink); text-decoration: none;
        transition: background .15s, border-color .15s;
    }
    .shop-pager__btn:hover, .shop-pager__num:hover { background: var(--bone); }
    .shop-pager__num--active { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
    .shop-pager__ellipsis { font-size: 15px; color: var(--slate); padding: 0 4px; }
    .grid-shop { display: grid; gap: var(--s4); grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 860px) { .grid-shop { grid-template-columns: repeat(3, 1fr); gap: var(--s5); } }
    .product-card {
        display: flex; flex-direction: column; text-decoration: none; color: inherit;
        border-radius: 4px; overflow: hidden; border: 1px solid var(--bone);
        transition: box-shadow .2s;
    }
    .product-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); }
    .product-card__img {
        aspect-ratio: 1 / 1; overflow: hidden; background: var(--bone);
        display: flex; align-items: center; justify-content: center;
    }
    .product-card__img img {
        width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease;
    }
    .product-card:hover .product-card__img img { transform: scale(1.04); }
    .product-card__img--empty { color: var(--slate); display: flex; align-items: center; justify-content: center; }
    .product-detail__main-img--empty { display: flex; align-items: center; justify-content: center; }
    .product-card__body { padding: var(--s3) var(--s4); flex: 1; display: flex; flex-direction: column; }
    .product-card__category {
        font-size: 12px; text-transform: uppercase; letter-spacing: .06em;
        color: var(--accent); margin-bottom: 6px;
    }
    .product-card__title { font-size: 16px; font-weight: 500; margin: 0 0 6px; line-height: 1.3; }
    .product-card__price { font-size: 15px; color: var(--slate); margin-top: auto; padding-top: 6px; }
    .shop-more { text-align: center; margin-top: var(--s5); }

    /* Product detail */
    .product-detail { display: grid; gap: var(--s6); }
    @media (min-width: 760px) { .product-detail { grid-template-columns: 1fr 1fr; } }
    .product-detail__images { display: flex; flex-direction: column; gap: var(--s3); }
    .product-detail__main-img { aspect-ratio: 1/1; overflow: hidden; background: var(--bone); border-radius: 4px; }
    .product-detail__main-img img { width: 100%; height: 100%; object-fit: cover; }
    .product-detail__thumbs { display: flex; gap: var(--s2); }
    .product-detail__thumb { width: 72px; height: 72px; overflow: hidden; border-radius: 4px; cursor: pointer; border: 2px solid transparent; }
    .product-detail__thumb img { width: 100%; height: 100%; object-fit: cover; }
    .product-detail__info { display: flex; flex-direction: column; gap: var(--s4); }
    .product-detail__cat { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--accent); }
    .product-detail__title { font-family: var(--font-display); font-size: 34px; margin: 0; line-height: 1.15; }
    .product-detail__price { font-size: 22px; font-weight: 600; color: var(--ink); }
    .product-detail__desc { color: var(--slate); line-height: 1.7; }
    .product-detail__desc p { margin: 0 0 var(--s3); }

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
    .row__sel { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .order-check { width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent); flex-shrink: 0; }
    .order-qty { width: 46px; font: inherit; font-size: 14px; padding: 3px 6px; border: 1px solid var(--bone); border-radius: 2px; text-align: center; color: var(--ink); background: var(--salt); }
    .order-bar { display: flex; align-items: center; justify-content: space-between; gap: var(--s4); margin-top: var(--s5); padding: var(--s3) var(--s4); background: var(--ink); border-radius: 4px; }
    .order-bar__count { color: #fff; font-size: 15px; }
    .order-bar__btn { white-space: nowrap; }

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
    .enquiry__wa { display: flex; align-items: center; gap: var(--s4); padding-top: var(--s4); margin-top: var(--s2); border-top: 1px solid var(--bone); }
    .enquiry__wa-or { color: var(--slate); font-size: 14px; white-space: nowrap; }
    .enquiry__wa-btn { flex: 1; text-align: center; }

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
        display: grid; gap: var(--s3);
    }
    .foot__row { display: flex; flex-wrap: wrap; gap: var(--s2) var(--s4); }
    /* Last, and quieter than the links above it. */
    .foot__row--copy { color: rgba(255,255,255,.38); }
    /* Pushed to the end of its own row on a wide screen, so the business's own
       pages read first and ours is a footnote. */
    @media (min-width: 720px) { .foot__powered { margin-left: auto; } }

    /* ---- Action bar ----------------------------------------------------
       Call, WhatsApp and the booking button, fixed to the foot of the screen
       on a phone. See partials/action-bar.blade.php for why it exists; what
       matters here is that it is the only fixed element on the page, and that
       it takes its height out of the flow with a spacer rather than with
       padding on the body, so it can never cover the last line of the footer.
       Gone above 1100px, where the bar at the top carries the same button. */

    .bar {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 30;
        display: flex; background: var(--salt);
        border-top: 1px solid var(--bone);
        box-shadow: 0 -6px 18px rgba(0,0,0,.10);
        /* The home indicator on an iPhone sits over the bottom 34px. */
        padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    .bar__item {
        flex: 1 1 0; min-width: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 3px; padding: 8px 6px; min-height: 56px;
        font-size: 11px; letter-spacing: .07em; text-transform: uppercase;
        text-decoration: none; color: var(--ink); text-align: center;
        border-left: 1px solid var(--bone);
    }
    .bar__item:first-child { border-left: 0; }
    /* The one filled item, and the widest: it is what the page is for. */
    .bar__item--primary { background: var(--accent); color: #fff; border-left: 0; flex-grow: 1.4; }
    .bar__icon { height: 20px; width: 20px; flex: none; }
    /* 56px of item, one border, and the pixel the two rounded apart when this
       was measured against the foot of a real page. */
    .bar__spacer { height: calc(58px + env(safe-area-inset-bottom, 0px)); }

    /* ---- Which screen a button is for ----------------------------------
       Every action button is placed per area *and per screen* (see
       App\Sites\ActionButtons), and a server cannot know the screen — guessing
       it from a user agent is how you serve the phone layout to a laptop. So
       the page is rendered once carrying both, and these two rules decide.

       1100px is the same line the menu uses to stop being a burger, and it is
       the only "this is a desktop" number in the stylesheet: WhatsApp being
       worth a button and a strip at the foot of the screen being worth its
       height are the same judgement about the same device.

       !important because these are worth more than any layout rule they land
       on: .btn sets display, .bar__item sets display, and this has to beat
       both wherever it is used. */
    @media (min-width: 1100px) { .at-phone { display: none !important; } }
    @media (max-width: 1099.98px) { .at-desktop { display: none !important; } }

    /* ---- Legal pages --------------------------------------------------- */

    .wrap--narrow { max-width: 760px; }
    .section--legal { padding: var(--s7) 0 var(--s6); }
    .section--legal h1 { font-family: var(--font-display); font-size: 34px; margin: 0 0 var(--s5); }

    /* ---- Lightbox ----------------------------------------------------- */

    .lb {
        display: none; position: fixed; inset: 0; z-index: 100;
        background: rgba(10,11,13,.92); cursor: zoom-out;
        align-items: center; justify-content: center;
        padding: var(--s4);
    }
    .lb.is-open { display: flex; }
    .lb__img {
        max-width: 100%; max-height: 90vh;
        object-fit: contain;
        box-shadow: 0 24px 80px rgba(0,0,0,.6);
        cursor: default;
        border-radius: 2px;
    }
    .lb__close {
        position: absolute; top: var(--s4); right: var(--s4);
        width: 44px; height: 44px;
        background: rgba(255,255,255,.12); border: 0; border-radius: 50%;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 20px; line-height: 1;
        transition: background .2s ease;
    }
    .lb__close:hover { background: rgba(255,255,255,.24); }
    .lb__nav {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 44px; height: 44px;
        background: rgba(255,255,255,.12); border: 0; border-radius: 50%;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 20px; line-height: 1;
        transition: background .2s ease;
    }
    .lb__nav:hover { background: rgba(255,255,255,.24); }
    .lb__nav--prev { left: var(--s4); }
    .lb__nav--next { right: var(--s4); }
    .lb__nav[hidden] { display: none; }
    .lb__nav:disabled { opacity: .3; cursor: default; }
    .lb__nav:disabled:hover { background: rgba(255,255,255,.12); }

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
        .product-card:hover .product-card__img img { transform: none; }
    }
</style>

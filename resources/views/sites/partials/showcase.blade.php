{{--
  The enterprise edition: what a showcase site adds on top of the standard one.

  Same markup, same blocks - this file only restyles and animates what is
  already on the page, scoped under `.sc` on the body. So a site can move
  between editions with one field (sites.edition), and the standard pages pay
  nothing for any of this: it is only included where the edition asks for it.

  What it adds: an opening screen that fills the phone, the headline arriving
  word by word, parallax on the hero and the photo bands, tour cards that are
  photographs first, a route that draws itself, and a glass bar once the page
  scrolls. Everything is an enhancement - without the script the page is
  complete, and `prefers-reduced-motion` switches all of it off.
--}}
@php ob_start(); @endphp
    .sc { --ease-out: cubic-bezier(.16, 1, .3, 1); }

    /* ---- Bar: dark glass once the page scrolls ------------------------
       Dark rather than the standard cream: it carries the photographs behind
       it, and a colourful badge sits quieter on it. The open menu panel stays
       cream, so that state keeps the light bar it belongs to. */
    .sc .nav.is-scrolled:not(.is-open) {
        background: color-mix(in srgb, var(--ink) 86%, transparent);
        box-shadow: none;
        -webkit-backdrop-filter: blur(14px) saturate(1.4); backdrop-filter: blur(14px) saturate(1.4);
    }
    .sc .nav.is-scrolled:not(.is-open) .nav__name,
    .sc .nav.is-scrolled:not(.is-open) .nav__brandtext { color: #fff; }
    .sc .nav.is-scrolled:not(.is-open) .nav__links a { color: rgba(255,255,255,.82); }
    .sc .nav.is-scrolled:not(.is-open) .nav__links a:hover { color: #fff; }
    .sc .nav.is-scrolled:not(.is-open) .nav__burger span { background: #fff; }
    .sc .nav__brandtext { font-weight: 600; }

    /* ---- Opening screen ---------------------------------------------- */
    .sc .hero__body { min-height: 100vh; min-height: 100svh; padding-bottom: var(--s8); }
    .sc .hero__media { will-change: transform; }
    .sc .hero__media img, .sc .hero__video { transform: scale(1.08); }
    .sc .hero::after {
        background:
            linear-gradient(90deg, rgba(10,11,13,.62) 0%, rgba(10,11,13,.28) 45%, transparent 75%),
            linear-gradient(180deg, rgba(10,11,13,.6) 0%, rgba(10,11,13,.12) 28%, rgba(10,11,13,.25) 60%, rgba(10,11,13,.9) 100%);
    }
    /* Film grain, a few hundred bytes of SVG noise: stops a flat sky banding. */
    .sc .hero::before {
        content: ''; position: absolute; inset: 0; z-index: 1; pointer-events: none; opacity: .14;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }
    .sc .hero h1 {
        font-size: clamp(50px, min(9vw, 12.5vh), 120px); line-height: .92; letter-spacing: -.035em; max-width: 14ch;
    }
    .sc .hero h1 .w { display: inline-block; opacity: 0; transform: translateY(.45em) rotate(2deg); animation: scWord 1s var(--ease-out) forwards; animation-delay: calc(var(--i) * 75ms + 150ms); }
    .sc .hero__eyebrow {
        display: inline-flex; align-items: center; gap: 10px; align-self: flex-start;
        padding: 7px 16px; border-radius: 999px; color: #fff;
        background: rgba(255,255,255,.1); box-shadow: inset 0 0 0 1px rgba(255,255,255,.22);
        -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
    }
    .sc .hero__eyebrow::before {
        content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--accent);
        box-shadow: 0 0 0 0 var(--accent); animation: scPulse 2.4s ease-out infinite;
    }
    .sc .hero__eyebrow, .sc .hero__subline, .sc .hero__cta { animation: scUp 1.1s var(--ease-out) both; }
    .sc .hero__eyebrow { animation-delay: .05s; }
    .sc .hero__subline { animation-delay: .55s; font-size: clamp(18px, 2.2vw, 23px); max-width: 40ch; }
    .sc .hero__cta { animation-delay: .75s; }
    .sc .hero .btn { padding: 17px 30px; border-radius: 999px; }
    .sc .hero__scroll {
        position: absolute; z-index: 2; left: 50%; bottom: var(--s5); transform: translateX(-50%);
        width: 28px; height: 46px; border-radius: 14px; box-shadow: inset 0 0 0 1.5px rgba(255,255,255,.55);
    }
    .sc .hero__scroll::after {
        content: ''; position: absolute; left: 50%; top: 9px; width: 4px; height: 9px; margin-left: -2px;
        border-radius: 2px; background: #fff; animation: scScroll 2s ease-in-out infinite;
    }
    /* Portrait clip: the whole screen on a phone, a card on a wide one. */
    @media (min-width: 900px) {
        /* The handset the clip was filmed on: a dark bezel, a speaker slit and
           the glass edge. It is drawn, not an image - nothing to load, and no
           particular make of phone to date the page. */
        .sc .hero--card .hero__phone {
            inset: auto; top: 50%; right: max(5vw, calc((100vw - var(--container)) / 2));
            width: clamp(250px, 23vw, 340px); aspect-ratio: 9 / 19; z-index: 3;
            padding: 11px 9px; border-radius: 44px;
            background: linear-gradient(150deg, #3a3f46 0%, #14171b 38%, #0b0d10 100%);
            box-shadow:
                0 40px 90px rgba(0,0,0,.55),
                inset 0 0 0 1.5px rgba(255,255,255,.22),
                inset 0 0 0 7px #0b0d10;
            transform: translateY(-50%) rotate(2.5deg);
        }
        /* The earpiece slit, and the glass the screen sits under. */
        .sc .hero--card .hero__phone::before {
            content: ''; position: absolute; z-index: 2; left: 50%; top: 5px;
            width: 58px; height: 5px; margin-left: -29px; border-radius: 3px; background: #23272c;
        }
        .sc .hero--card .hero__phone::after {
            content: ''; position: absolute; inset: 11px 9px; border-radius: 34px; pointer-events: none;
            background: linear-gradient(115deg, rgba(255,255,255,.16) 0%, rgba(255,255,255,0) 32%);
        }
        .sc .hero--card .hero__video {
            position: relative; inset: auto; width: 100%; height: 100%;
            border-radius: 34px; object-fit: cover;
            transform: scale(.98); transition: opacity 1.2s ease, transform 1.4s var(--ease-out);
        }
        .sc .hero--card .hero__video.is-playing { transform: none; }
        .sc .hero--card .hero__body { padding-right: clamp(280px, 30vw, 420px); }
        .sc .hero--card .hero__videonote {
            display: block; position: absolute; z-index: 3; margin: 0; text-align: center;
            right: max(5vw, calc((100vw - var(--container)) / 2));
            width: clamp(250px, 23vw, 340px);
            /* Half the handset's own height below its middle: 9/19 aspect, so
               half is the width times 19/18. */
            top: calc(50% + (clamp(250px, 23vw, 340px) * 19 / 18) + 16px);
            transform: rotate(2.5deg);
            font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.75);
        }
    }
    @media (max-width: 899.98px) {
        .sc .hero--card .hero__videonote { display: none; }
    }

    /* ---- The foot of the page: dark, with a photograph in it ----------
       A faint picture on a light band read as a rendering fault. On ink, at
       half strength, it reads as the closing credits it is meant to be. The
       enquiry form keeps its own white card, so everything typed stays dark
       on light. */
    .sc .section--photo { background: var(--ink); color: #fff; }
    .sc .section--photo .section__photo img { opacity: .55; }
    .sc .section--photo::after, .sc .section--tint.section--photo::after {
        background: linear-gradient(180deg, rgba(22,24,28,.9) 0%, rgba(22,24,28,.5) 45%, rgba(22,24,28,.88) 100%);
    }
    .sc .section--photo h2, .sc .section--photo .faq__item summary, .sc .section--photo .channel a { color: #fff; }
    .sc .section--photo .lead, .sc .section--photo .prose, .sc .section--photo .note,
    .sc .section--photo .rule__label, .sc .section--photo .channel__label, .sc .section--photo .faq__a { color: rgba(255,255,255,.75); }
    .sc .section--photo .rule, .sc .section--photo .faq, .sc .section--photo .faq__item { border-color: rgba(255,255,255,.18); }
    .sc .section--photo .enquiry { border: 0; border-radius: 18px; box-shadow: 0 30px 80px rgba(0,0,0,.45); }

    /* ---- Field-notebook sketches around the video card ---------------- */
    .doodles { display: none; }
    @media (min-width: 900px) {
        .sc .doodles {
            display: block; position: absolute; z-index: 2; pointer-events: none;
            top: 50%; right: max(5vw, calc((100vw - var(--container)) / 2));
            width: clamp(250px, 23vw, 340px); aspect-ratio: 9 / 19; transform: translateY(-50%);
        }
        .sc .doodle { position: absolute; fill: none; stroke: rgba(255,255,255,.82); stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; overflow: visible; filter: drop-shadow(0 2px 6px rgba(0,0,0,.45)); }
        .sc .doodle--binoculars { width: 34%; left: -44%; top: 4%; transform: rotate(-12deg); }
        .sc .doodle--compass { width: 26%; right: -30%; top: -2%; }
        .sc .doodle--acacia { width: 40%; right: -36%; top: 50%; }
        .sc .doodle--jeep { width: 70%; left: -76%; bottom: 6%; }
        .sc .doodle--trail { width: 44%; left: -50%; top: 30%; opacity: .7; }
        .sc .doodle path { stroke-dasharray: 1; stroke-dashoffset: 1; animation: scDraw 1.6s var(--ease-out) forwards; }
        .sc .doodle--binoculars path { animation-delay: 1.2s; }
        .sc .doodle--compass path { animation-delay: 1.5s; }
        .sc .doodle--trail path { animation-delay: 1.9s; }
        .sc .doodle--jeep path { animation-delay: 2.1s; }
        .sc .doodle--acacia path { animation-delay: 2.5s; }
        .sc .doodle--compass { animation: scSpin 60s linear infinite; }
        .sc .doodle--jeep { animation: scBump 2.8s ease-in-out infinite 3.5s; }
    }
    @keyframes scDraw { to { stroke-dashoffset: 0; } }
    @keyframes scSpin { to { transform: rotate(360deg); } }
    @keyframes scBump { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px) rotate(-.6deg); } }
    .hero__scroll { display: none; }
    .sc .hero__scroll { display: block; }
    @media (max-width: 639.98px) { .sc .hero__scroll { display: none; } }

    /* ---- Type and rhythm --------------------------------------------- */
    .sc .section { padding: var(--s8) 0; }
    .sc h2 { font-size: clamp(38px, 5.6vw, 72px); line-height: 1; letter-spacing: -.03em; max-width: 16ch; }
    .sc .lead { font-size: clamp(18px, 1.8vw, 21px); }
    .sc .btn { border-radius: 999px; }
    .js .sc .reveal { transform: translateY(36px); }
    .js .sc .reveal.in { transition: opacity 1s var(--ease-out), transform 1.2s var(--ease-out); }

    /* ---- Tour cards: the photograph is the card ----------------------- */
    .sc .offers { gap: var(--s4); }
    /* Four cards: two by two, wide, rather than three and an orphan. */
    @media (min-width: 760px) { .sc .offers--4 { grid-template-columns: repeat(2, 1fr); } }
    .sc .offer-card {
        position: relative; isolation: isolate; min-height: 560px; overflow: hidden;
        border-radius: 22px; border: 0; background: var(--ink); color: #fff;
    }
    .sc .offer-card__media { position: absolute; inset: 0; z-index: -2; }
    .sc .offer-card__media img { height: 100%; aspect-ratio: auto; transition: transform 1.6s var(--ease-out); }
    .sc .offer-card:hover .offer-card__media img { transform: scale(1.07); }
    .sc .offer-card::after {
        content: ''; position: absolute; inset: 0; z-index: -1; pointer-events: none;
        background: linear-gradient(180deg, rgba(10,11,13,.05) 20%, rgba(10,11,13,.55) 55%, rgba(10,11,13,.92) 100%);
    }
    .sc .offer-card__body { justify-content: flex-end; padding: var(--s5); }
    .sc .offer-card__meta {
        align-self: flex-start; padding: 5px 12px; border-radius: 999px; color: #fff;
        background: rgba(255,255,255,.14); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
    }
    .sc .offer-card h3 { font-size: clamp(30px, 3vw, 40px); line-height: 1; letter-spacing: -.02em; color: #fff; }
    .sc .offer-card__text { color: rgba(255,255,255,.8); }
    .sc .offer-card__price { color: #fff; }
    .sc .offer-card__foot { margin-top: 0; }
    .sc .offer-card .btn--ghost { color: #fff; box-shadow: inset 0 0 0 1px rgba(255,255,255,.55); }
    .sc .offer-card .btn--ghost:hover { background: rgba(255,255,255,.12); }

    /* ---- The route draws itself ---------------------------------------- */
    .sc .trip__body { border-left-color: transparent; }
    .sc .trip__body::after {
        content: ''; position: absolute; left: -1px; top: 0; bottom: 0; width: 2px;
        background: var(--accent); transform: scaleY(0); transform-origin: top;
        transition: transform 1.2s var(--ease-out) .15s;
    }
    .sc .trip__stop.in .trip__body::after { transform: scaleY(1); }
    .sc .trip__stop:last-child .trip__body::after { display: none; }
    .sc .trip__body::before { z-index: 1; transition: transform .6s var(--ease-out); }
    .sc .trip__stop.in .trip__body::before { background: var(--accent); }
    .sc .trip__body h3 { font-size: clamp(24px, 2.6vw, 32px); }
    .sc .trip__day { font-size: 13px; }

    /* ---- Photo bands and galleries ------------------------------------ */
    .sc .band { min-height: 86vh; display: flex; align-items: flex-end; }
    .sc .band__img { will-change: transform; top: -12%; height: 124%; }
    .sc .band__statement { font-size: clamp(36px, 6vw, 84px); line-height: .98; letter-spacing: -.03em; max-width: 18ch; }
    .sc .grid-photos figure { border-radius: 14px; overflow: hidden; }

    @keyframes scWord { to { opacity: 1; transform: none; } }
    @keyframes scUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
    @keyframes scPulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 70%, transparent); } 80%, 100% { box-shadow: 0 0 0 12px transparent; } }
    @keyframes scScroll { 0% { opacity: 0; transform: translateY(0); } 30% { opacity: 1; } 100% { opacity: 0; transform: translateY(14px); } }

    @media (prefers-reduced-motion: reduce) {
        .sc .hero h1 .w { opacity: 1; transform: none; animation: none; }
        .sc .hero__eyebrow, .sc .hero__subline, .sc .hero__cta, .sc .hero__eyebrow::before, .sc .hero__scroll::after { animation: none; }
        .sc .hero__media, .sc .band__img { transform: none !important; }
        .sc .trip__body::after { transform: none; transition: none; }
        .sc .offer-card:hover .offer-card__media img { transform: none; }
        .sc .doodle, .sc .doodle path { animation: none; stroke-dashoffset: 0; }
    }
@php $showcaseCss = ob_get_clean(); @endphp
<style>{!! \App\Sites\Rendering\InlineCss::minify((string) $showcaseCss) !!}</style>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        {{-- The headline arrives word by word. Only text nodes are split, so
             the line breaks the business typed stay where they are. --}}
        var h1 = document.querySelector('.hero h1');
        if (h1) {
            var i = 0;
            Array.prototype.slice.call(h1.childNodes).forEach(function (node) {
                if (node.nodeType !== 3) return;
                var frag = document.createDocumentFragment();
                node.textContent.split(/(\s+)/).forEach(function (part) {
                    if (!part) return;
                    if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
                    var w = document.createElement('span');
                    w.className = 'w';
                    w.style.setProperty('--i', i++);
                    w.textContent = part;
                    frag.appendChild(w);
                });
                h1.replaceChild(frag, node);
            });
        }

        {{-- Parallax: the hero and every photo band move slower than the page. --}}
        var hero = document.querySelector('.hero__media');
        var bands = document.querySelectorAll('.band__img');
        var ticking = false;
        var move = function () {
            ticking = false;
            var y = window.scrollY, vh = window.innerHeight;
            if (hero && y < vh * 1.2) hero.style.transform = 'translate3d(0,' + (y * 0.32) + 'px,0)';
            bands.forEach(function (img) {
                var r = img.parentNode.getBoundingClientRect();
                if (r.bottom < 0 || r.top > vh) return;
                img.style.transform = 'translate3d(0,' + ((r.top + r.height / 2 - vh / 2) * -0.12) + 'px,0)';
            });
        };
        addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(move); }
        }, { passive: true });
        move();
    });
</script>

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
            inset: auto; top: 44%; right: max(5vw, calc((100vw - var(--container)) / 2));
            width: clamp(238px, 22vw, 323px); aspect-ratio: 9 / 19; z-index: 3;
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
            width: clamp(238px, 22vw, 323px);
            /* Half the handset's own height below its middle: 9/19 aspect, so
               half is the width times 19/18. */
            top: calc(50% + (clamp(238px, 22vw, 323px) * 19 / 18) + 16px);
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

    /* ---- Expedition: the map as an old chart on the canyon -------------
       Aged paper with burnt edges and two strips of tape, a red road, an X,
       a stamped title, and the stop list as a field log. Everything is CSS
       over the same markup the standard page has. */
    .sc .section--map { color: #F3E6C8; }
    .sc .section--map h2 { color: #F6E7C4; text-shadow: 0 4px 30px rgba(0,0,0,.6); }
    .sc .section--map .lead { color: rgba(246,231,196,.82); }
    .sc .section--map .rule { border-color: rgba(226,171,108,.35); }
    .sc .section--map .rule__label { color: #E2AB6C; }
    @media (min-width: 900px) { .sc .routemap { grid-template-columns: 1fr 1.7fr; } }
    .sc .routemap__legend li { border-bottom-color: rgba(226,171,108,.3); font-family: 'Courier New', Courier, monospace; font-size: 16px; letter-spacing: .02em; }
    .sc .routemap__legend span { color: #E2AB6C; }
    /* The sheet: torn all round with a few deep tears, and a charred rim
       drawn by a dark layer of the same shape just behind the paper. The
       outline is fixed (not random per page) so the page never jumps. */
    .sc .routemap__sheet {
        --torn: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1000 1000' preserveAspectRatio='none'%3E%3Cpath d='M0.0 12.7L12.5 13.3L25.0 15.4L37.5 16.3L50.0 15.9L62.5 15.9L75.0 17.0L87.5 16.3L100.0 14.4L112.5 10.8L125.0 10.0L137.5 8.1L150.0 6.0Q146.7 53.4 128.1 92.1Q148.5 53.4 153.7 6.0L162.5 5.5L166.0 13.6L175.0 5.8L182.9 14.6L187.5 6.7L192.7 15.5L200.0 6.6L205.9 19.6L212.5 5.4L225.0 5.7Q224.8 72.5 223.9 127.2Q227.5 72.5 230.3 5.7L237.5 5.9L250.0 3.7L262.5 1.7L275.0 -0.3L287.5 1.0L300.0 0.6L312.5 -0.6L325.0 1.6L337.5 4.5L350.0 5.1L362.5 5.1L375.0 6.0L380.2 18.7L387.5 9.2L392.2 20.5L400.0 7.9L408.3 21.1L412.5 5.9L417.5 12.1L425.0 6.2L432.3 10.7L437.5 7.5L444.3 21.1L450.0 9.4L454.0 15.6L462.5 9.6L475.0 10.1L487.5 13.4L500.0 16.4L512.5 16.3L525.0 17.3L537.5 18.7L550.0 17.5L562.5 15.2L575.0 14.1L587.5 14.0L600.0 14.6L612.5 13.3L625.0 13.2L628.1 26.3L637.5 15.1L643.3 27.7L650.0 15.7L656.5 20.8L662.5 14.7L666.3 29.7L675.0 13.4L683.5 24.2L687.5 13.5L700.0 12.0L712.5 8.9L725.0 5.9L737.5 5.0L750.0 4.7L762.5 3.5L775.0 2.9L787.5 4.8L800.0 5.9L812.5 4.7L825.0 3.2L837.5 3.8L850.0 3.1L862.5 1.9L875.0 -0.9L887.5 -0.4L900.0 1.6L912.5 1.9L925.0 2.3L937.5 5.1L950.0 8.4L962.5 9.9L975.0 8.3L987.5 10.0L1000.0 11.1L995.0 0.0L992.8 12.5L992.1 25.0L990.0 37.5L990.6 50.0L991.0 62.5L989.4 75.0L991.7 87.5L990.4 100.0L987.9 112.5L986.7 125.0L985.2 137.5L984.2 150.0L984.7 162.5L984.1 175.0L986.7 187.5L987.8 200.0L987.1 212.5L987.9 225.0L986.5 237.5L985.5 250.0L980.8 253.9L987.0 262.5L973.8 271.4L987.7 275.0L980.7 279.7L988.9 287.5L982.2 292.3L992.2 300.0L977.5 304.4L993.1 312.5L982.2 320.1Q912.6 311.0 846.6 302.3Q912.6 313.7 993.1 318.0L995.2 325.0L993.8 337.5L992.4 350.0L994.1 362.5L993.2 375.0L992.9 387.5L997.2 400.0L996.2 412.5L998.1 425.0L999.1 437.5L995.2 450.0L995.1 462.5L994.8 475.0L991.9 487.5L993.1 500.0L993.7 512.5L992.8 525.0L994.9 537.5L991.4 550.0L991.3 562.5L990.2 575.0L986.0 587.5L987.0 600.0L986.0 612.5L985.6 625.0L988.7 637.5L987.1 650.0L987.3 662.5L986.8 675.0L983.6 687.5L984.9 700.0L984.5 712.5L984.6 725.0L988.8 737.5L989.3 750.0L989.8 762.5L992.3 775.0L989.7 787.5L989.7 800.0L985.6 807.1L990.2 812.5L979.1 816.9L989.1 825.0L980.0 830.2L991.9 837.5L983.6 845.7L994.5 850.0L988.3 854.3L995.8 862.5L982.2 868.2L997.3 875.0L997.3 887.5L995.4 900.0L996.9 912.5L993.1 925.0L993.7 937.5L995.0 950.0L994.9 962.5L997.3 975.0L997.3 987.5L995.0 1000.0L1000.0 994.6L987.5 994.4L975.0 997.5L962.5 998.9L950.0 999.2L937.5 999.4L925.0 999.8L912.5 995.1L900.0 995.3L887.5 993.0L875.0 990.8L862.5 992.8L850.0 993.2L837.5 991.3L825.0 994.0L812.5 993.5Q810.8 937.6 801.0 891.8Q808.4 937.6 807.8 993.5L800.0 991.8L787.5 990.8Q784.4 936.8 766.7 892.6Q781.9 936.8 782.5 990.8L775.0 987.5L762.5 985.1L750.0 984.4L737.5 982.9L725.0 981.9L712.5 984.9L700.0 985.8L692.4 975.3L687.5 987.0L679.8 977.0L675.0 989.1L666.9 979.1L662.5 987.1L659.1 980.2L650.0 986.5L641.2 980.9L637.5 987.5L629.6 977.6L625.0 984.4L612.5 985.3L600.0 987.2L587.5 987.7L575.0 989.8L562.5 994.4L550.0 993.6L537.5 996.2L525.0 997.7L512.5 994.5L500.0 994.4L487.5 995.3L475.0 992.7L462.5 993.8L450.0 996.5L437.5 996.2L425.0 997.7L412.5 1000.3L400.0 997.5L387.5 997.7L375.0 996.2L362.5 992.5L350.0 991.0L346.6 978.4L337.5 989.4L332.8 978.4L325.0 988.3L317.1 983.2L312.5 989.1L308.4 981.3L300.0 990.7L287.5 989.5L275.0 991.7L262.5 991.0L250.0 987.9L237.5 987.0L225.0 985.4L212.5 982.2L208.5 972.5L200.0 984.0L196.1 979.2L187.5 981.8L180.3 968.3L175.0 982.5L170.5 974.9L162.5 986.3L154.0 975.6L150.0 987.8L142.7 979.2L137.5 987.9L132.9 982.1L125.0 991.8L112.5 989.3L100.0 987.8L87.5 989.3L75.0 988.0L62.5 987.7L50.0 990.8L37.5 992.0L25.0 993.5L12.5 997.5L0.0 997.4L13.9 1000.0L17.7 987.5L18.2 975.0L17.1 962.5L14.9 950.0L14.1 937.5L10.8 925.0L7.4 912.5L7.8 900.0L9.6 887.5L8.9 875.0L9.4 862.5L9.0 850.0L4.6 837.5L1.4 825.0L13.8 821.8L0.4 812.5L12.0 803.6L0.9 800.0L8.0 791.2L-0.1 787.5L8.9 783.5L3.7 775.0L12.0 767.9L6.9 762.5L12.3 755.6L5.8 750.0L12.8 746.8L6.1 737.5L5.9 725.0L4.1 712.5L2.8 700.0L7.3 687.5L9.8 675.0L11.7 662.5L14.9 650.0L16.5 637.5L15.4 625.0L12.9 612.5L13.6 600.0Q90.4 603.9 153.2 626.0Q90.4 602.0 13.6 596.3L12.7 587.5L13.8 575.0L16.3 562.5L24.4 554.2L17.5 550.0L24.2 546.6L16.9 537.5L26.3 532.7L15.1 525.0L21.9 517.7L14.0 512.5L22.2 504.3L8.8 500.0L19.0 496.1L5.9 487.5L16.0 484.0L6.4 475.0L6.9 462.5L6.8 450.0L8.6 437.5L7.8 425.0L4.0 412.5L2.1 400.0L1.4 387.5L0.5 375.0L-1.0 362.5L2.6 350.0L5.9 337.5L7.0 325.0L6.9 312.5L8.0 300.0L5.0 287.5L4.6 275.0L7.8 262.5L9.7 250.0L12.1 237.5L16.1 225.0L18.2 212.5L16.2 200.0L14.8 187.5L14.9 175.0L12.9 162.5L11.9 150.0L14.6 137.5L16.2 125.0L16.5 112.5L15.1 100.0L13.6 87.5L9.6 75.0L6.0 62.5L5.6 50.0L5.7 37.5L4.6 25.0L6.6 12.5L7.3 0.0Z'/%3E%3C/svg%3E");
        position: relative; transform: rotate(-2deg);
        filter: drop-shadow(0 0 1px #1E0F05) drop-shadow(0 0 1.2px rgba(40,20,6,.9)) drop-shadow(0 34px 50px rgba(0,0,0,.65));
    }
    .sc .routemap__burn {
        position: relative;
    }
    .sc .routemap__paper {
        position: relative; border-radius: 0; transform: none; padding: 26px;
        background:
            radial-gradient(120% 90% at 50% 50%, transparent 55%, rgba(110,62,20,.55) 100%),
            #EBD6A6;
        box-shadow: inset 0 0 70px rgba(90,45,10,.65), inset 0 0 14px rgba(60,30,8,.55);
        -webkit-mask: var(--torn) 0 0 / 100% 100% no-repeat; mask: var(--torn) 0 0 / 100% 100% no-repeat;
    }
    /* Scorched and aged where it tore: a brown halo along the edge and into
       every cut, blurred, multiplied onto the paper. Same outline as the mask. */
    .sc .routemap__paper::after {
        content: ''; position: absolute; inset: 0; pointer-events: none; z-index: 1;
        background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1000 1000' preserveAspectRatio='none'%3E%3Cfilter id='b' x='-10%' y='-10%' width='120%' height='120%'%3E%3CfeGaussianBlur stdDeviation='4'/%3E%3C/filter%3E%3Cfilter id='c'%3E%3CfeGaussianBlur stdDeviation='4'/%3E%3C/filter%3E%3Cpath d='M0.0 12.7L12.5 13.3L25.0 15.4L37.5 16.3L50.0 15.9L62.5 15.9L75.0 17.0L87.5 16.3L100.0 14.4L112.5 10.8L125.0 10.0L137.5 8.1L150.0 6.0Q146.7 53.4 128.1 92.1Q148.5 53.4 153.7 6.0L162.5 5.5L166.0 13.6L175.0 5.8L182.9 14.6L187.5 6.7L192.7 15.5L200.0 6.6L205.9 19.6L212.5 5.4L225.0 5.7Q224.8 72.5 223.9 127.2Q227.5 72.5 230.3 5.7L237.5 5.9L250.0 3.7L262.5 1.7L275.0 -0.3L287.5 1.0L300.0 0.6L312.5 -0.6L325.0 1.6L337.5 4.5L350.0 5.1L362.5 5.1L375.0 6.0L380.2 18.7L387.5 9.2L392.2 20.5L400.0 7.9L408.3 21.1L412.5 5.9L417.5 12.1L425.0 6.2L432.3 10.7L437.5 7.5L444.3 21.1L450.0 9.4L454.0 15.6L462.5 9.6L475.0 10.1L487.5 13.4L500.0 16.4L512.5 16.3L525.0 17.3L537.5 18.7L550.0 17.5L562.5 15.2L575.0 14.1L587.5 14.0L600.0 14.6L612.5 13.3L625.0 13.2L628.1 26.3L637.5 15.1L643.3 27.7L650.0 15.7L656.5 20.8L662.5 14.7L666.3 29.7L675.0 13.4L683.5 24.2L687.5 13.5L700.0 12.0L712.5 8.9L725.0 5.9L737.5 5.0L750.0 4.7L762.5 3.5L775.0 2.9L787.5 4.8L800.0 5.9L812.5 4.7L825.0 3.2L837.5 3.8L850.0 3.1L862.5 1.9L875.0 -0.9L887.5 -0.4L900.0 1.6L912.5 1.9L925.0 2.3L937.5 5.1L950.0 8.4L962.5 9.9L975.0 8.3L987.5 10.0L1000.0 11.1L995.0 0.0L992.8 12.5L992.1 25.0L990.0 37.5L990.6 50.0L991.0 62.5L989.4 75.0L991.7 87.5L990.4 100.0L987.9 112.5L986.7 125.0L985.2 137.5L984.2 150.0L984.7 162.5L984.1 175.0L986.7 187.5L987.8 200.0L987.1 212.5L987.9 225.0L986.5 237.5L985.5 250.0L980.8 253.9L987.0 262.5L973.8 271.4L987.7 275.0L980.7 279.7L988.9 287.5L982.2 292.3L992.2 300.0L977.5 304.4L993.1 312.5L982.2 320.1Q912.6 311.0 846.6 302.3Q912.6 313.7 993.1 318.0L995.2 325.0L993.8 337.5L992.4 350.0L994.1 362.5L993.2 375.0L992.9 387.5L997.2 400.0L996.2 412.5L998.1 425.0L999.1 437.5L995.2 450.0L995.1 462.5L994.8 475.0L991.9 487.5L993.1 500.0L993.7 512.5L992.8 525.0L994.9 537.5L991.4 550.0L991.3 562.5L990.2 575.0L986.0 587.5L987.0 600.0L986.0 612.5L985.6 625.0L988.7 637.5L987.1 650.0L987.3 662.5L986.8 675.0L983.6 687.5L984.9 700.0L984.5 712.5L984.6 725.0L988.8 737.5L989.3 750.0L989.8 762.5L992.3 775.0L989.7 787.5L989.7 800.0L985.6 807.1L990.2 812.5L979.1 816.9L989.1 825.0L980.0 830.2L991.9 837.5L983.6 845.7L994.5 850.0L988.3 854.3L995.8 862.5L982.2 868.2L997.3 875.0L997.3 887.5L995.4 900.0L996.9 912.5L993.1 925.0L993.7 937.5L995.0 950.0L994.9 962.5L997.3 975.0L997.3 987.5L995.0 1000.0L1000.0 994.6L987.5 994.4L975.0 997.5L962.5 998.9L950.0 999.2L937.5 999.4L925.0 999.8L912.5 995.1L900.0 995.3L887.5 993.0L875.0 990.8L862.5 992.8L850.0 993.2L837.5 991.3L825.0 994.0L812.5 993.5Q810.8 937.6 801.0 891.8Q808.4 937.6 807.8 993.5L800.0 991.8L787.5 990.8Q784.4 936.8 766.7 892.6Q781.9 936.8 782.5 990.8L775.0 987.5L762.5 985.1L750.0 984.4L737.5 982.9L725.0 981.9L712.5 984.9L700.0 985.8L692.4 975.3L687.5 987.0L679.8 977.0L675.0 989.1L666.9 979.1L662.5 987.1L659.1 980.2L650.0 986.5L641.2 980.9L637.5 987.5L629.6 977.6L625.0 984.4L612.5 985.3L600.0 987.2L587.5 987.7L575.0 989.8L562.5 994.4L550.0 993.6L537.5 996.2L525.0 997.7L512.5 994.5L500.0 994.4L487.5 995.3L475.0 992.7L462.5 993.8L450.0 996.5L437.5 996.2L425.0 997.7L412.5 1000.3L400.0 997.5L387.5 997.7L375.0 996.2L362.5 992.5L350.0 991.0L346.6 978.4L337.5 989.4L332.8 978.4L325.0 988.3L317.1 983.2L312.5 989.1L308.4 981.3L300.0 990.7L287.5 989.5L275.0 991.7L262.5 991.0L250.0 987.9L237.5 987.0L225.0 985.4L212.5 982.2L208.5 972.5L200.0 984.0L196.1 979.2L187.5 981.8L180.3 968.3L175.0 982.5L170.5 974.9L162.5 986.3L154.0 975.6L150.0 987.8L142.7 979.2L137.5 987.9L132.9 982.1L125.0 991.8L112.5 989.3L100.0 987.8L87.5 989.3L75.0 988.0L62.5 987.7L50.0 990.8L37.5 992.0L25.0 993.5L12.5 997.5L0.0 997.4L13.9 1000.0L17.7 987.5L18.2 975.0L17.1 962.5L14.9 950.0L14.1 937.5L10.8 925.0L7.4 912.5L7.8 900.0L9.6 887.5L8.9 875.0L9.4 862.5L9.0 850.0L4.6 837.5L1.4 825.0L13.8 821.8L0.4 812.5L12.0 803.6L0.9 800.0L8.0 791.2L-0.1 787.5L8.9 783.5L3.7 775.0L12.0 767.9L6.9 762.5L12.3 755.6L5.8 750.0L12.8 746.8L6.1 737.5L5.9 725.0L4.1 712.5L2.8 700.0L7.3 687.5L9.8 675.0L11.7 662.5L14.9 650.0L16.5 637.5L15.4 625.0L12.9 612.5L13.6 600.0Q90.4 603.9 153.2 626.0Q90.4 602.0 13.6 596.3L12.7 587.5L13.8 575.0L16.3 562.5L24.4 554.2L17.5 550.0L24.2 546.6L16.9 537.5L26.3 532.7L15.1 525.0L21.9 517.7L14.0 512.5L22.2 504.3L8.8 500.0L19.0 496.1L5.9 487.5L16.0 484.0L6.4 475.0L6.9 462.5L6.8 450.0L8.6 437.5L7.8 425.0L4.0 412.5L2.1 400.0L1.4 387.5L0.5 375.0L-1.0 362.5L2.6 350.0L5.9 337.5L7.0 325.0L6.9 312.5L8.0 300.0L5.0 287.5L4.6 275.0L7.8 262.5L9.7 250.0L12.1 237.5L16.1 225.0L18.2 212.5L16.2 200.0L14.8 187.5L14.9 175.0L12.9 162.5L11.9 150.0L14.6 137.5L16.2 125.0L16.5 112.5L15.1 100.0L13.6 87.5L9.6 75.0L6.0 62.5L5.6 50.0L5.7 37.5L4.6 25.0L6.6 12.5L7.3 0.0Z' fill='none' stroke='%235A2E0E' stroke-width='12' stroke-opacity='.26' filter='url%28%23b%29'/%3E%3Cpath d='M0.0 12.7L12.5 13.3L25.0 15.4L37.5 16.3L50.0 15.9L62.5 15.9L75.0 17.0L87.5 16.3L100.0 14.4L112.5 10.8L125.0 10.0L137.5 8.1L150.0 6.0Q146.7 53.4 128.1 92.1Q148.5 53.4 153.7 6.0L162.5 5.5L166.0 13.6L175.0 5.8L182.9 14.6L187.5 6.7L192.7 15.5L200.0 6.6L205.9 19.6L212.5 5.4L225.0 5.7Q224.8 72.5 223.9 127.2Q227.5 72.5 230.3 5.7L237.5 5.9L250.0 3.7L262.5 1.7L275.0 -0.3L287.5 1.0L300.0 0.6L312.5 -0.6L325.0 1.6L337.5 4.5L350.0 5.1L362.5 5.1L375.0 6.0L380.2 18.7L387.5 9.2L392.2 20.5L400.0 7.9L408.3 21.1L412.5 5.9L417.5 12.1L425.0 6.2L432.3 10.7L437.5 7.5L444.3 21.1L450.0 9.4L454.0 15.6L462.5 9.6L475.0 10.1L487.5 13.4L500.0 16.4L512.5 16.3L525.0 17.3L537.5 18.7L550.0 17.5L562.5 15.2L575.0 14.1L587.5 14.0L600.0 14.6L612.5 13.3L625.0 13.2L628.1 26.3L637.5 15.1L643.3 27.7L650.0 15.7L656.5 20.8L662.5 14.7L666.3 29.7L675.0 13.4L683.5 24.2L687.5 13.5L700.0 12.0L712.5 8.9L725.0 5.9L737.5 5.0L750.0 4.7L762.5 3.5L775.0 2.9L787.5 4.8L800.0 5.9L812.5 4.7L825.0 3.2L837.5 3.8L850.0 3.1L862.5 1.9L875.0 -0.9L887.5 -0.4L900.0 1.6L912.5 1.9L925.0 2.3L937.5 5.1L950.0 8.4L962.5 9.9L975.0 8.3L987.5 10.0L1000.0 11.1L995.0 0.0L992.8 12.5L992.1 25.0L990.0 37.5L990.6 50.0L991.0 62.5L989.4 75.0L991.7 87.5L990.4 100.0L987.9 112.5L986.7 125.0L985.2 137.5L984.2 150.0L984.7 162.5L984.1 175.0L986.7 187.5L987.8 200.0L987.1 212.5L987.9 225.0L986.5 237.5L985.5 250.0L980.8 253.9L987.0 262.5L973.8 271.4L987.7 275.0L980.7 279.7L988.9 287.5L982.2 292.3L992.2 300.0L977.5 304.4L993.1 312.5L982.2 320.1Q912.6 311.0 846.6 302.3Q912.6 313.7 993.1 318.0L995.2 325.0L993.8 337.5L992.4 350.0L994.1 362.5L993.2 375.0L992.9 387.5L997.2 400.0L996.2 412.5L998.1 425.0L999.1 437.5L995.2 450.0L995.1 462.5L994.8 475.0L991.9 487.5L993.1 500.0L993.7 512.5L992.8 525.0L994.9 537.5L991.4 550.0L991.3 562.5L990.2 575.0L986.0 587.5L987.0 600.0L986.0 612.5L985.6 625.0L988.7 637.5L987.1 650.0L987.3 662.5L986.8 675.0L983.6 687.5L984.9 700.0L984.5 712.5L984.6 725.0L988.8 737.5L989.3 750.0L989.8 762.5L992.3 775.0L989.7 787.5L989.7 800.0L985.6 807.1L990.2 812.5L979.1 816.9L989.1 825.0L980.0 830.2L991.9 837.5L983.6 845.7L994.5 850.0L988.3 854.3L995.8 862.5L982.2 868.2L997.3 875.0L997.3 887.5L995.4 900.0L996.9 912.5L993.1 925.0L993.7 937.5L995.0 950.0L994.9 962.5L997.3 975.0L997.3 987.5L995.0 1000.0L1000.0 994.6L987.5 994.4L975.0 997.5L962.5 998.9L950.0 999.2L937.5 999.4L925.0 999.8L912.5 995.1L900.0 995.3L887.5 993.0L875.0 990.8L862.5 992.8L850.0 993.2L837.5 991.3L825.0 994.0L812.5 993.5Q810.8 937.6 801.0 891.8Q808.4 937.6 807.8 993.5L800.0 991.8L787.5 990.8Q784.4 936.8 766.7 892.6Q781.9 936.8 782.5 990.8L775.0 987.5L762.5 985.1L750.0 984.4L737.5 982.9L725.0 981.9L712.5 984.9L700.0 985.8L692.4 975.3L687.5 987.0L679.8 977.0L675.0 989.1L666.9 979.1L662.5 987.1L659.1 980.2L650.0 986.5L641.2 980.9L637.5 987.5L629.6 977.6L625.0 984.4L612.5 985.3L600.0 987.2L587.5 987.7L575.0 989.8L562.5 994.4L550.0 993.6L537.5 996.2L525.0 997.7L512.5 994.5L500.0 994.4L487.5 995.3L475.0 992.7L462.5 993.8L450.0 996.5L437.5 996.2L425.0 997.7L412.5 1000.3L400.0 997.5L387.5 997.7L375.0 996.2L362.5 992.5L350.0 991.0L346.6 978.4L337.5 989.4L332.8 978.4L325.0 988.3L317.1 983.2L312.5 989.1L308.4 981.3L300.0 990.7L287.5 989.5L275.0 991.7L262.5 991.0L250.0 987.9L237.5 987.0L225.0 985.4L212.5 982.2L208.5 972.5L200.0 984.0L196.1 979.2L187.5 981.8L180.3 968.3L175.0 982.5L170.5 974.9L162.5 986.3L154.0 975.6L150.0 987.8L142.7 979.2L137.5 987.9L132.9 982.1L125.0 991.8L112.5 989.3L100.0 987.8L87.5 989.3L75.0 988.0L62.5 987.7L50.0 990.8L37.5 992.0L25.0 993.5L12.5 997.5L0.0 997.4L13.9 1000.0L17.7 987.5L18.2 975.0L17.1 962.5L14.9 950.0L14.1 937.5L10.8 925.0L7.4 912.5L7.8 900.0L9.6 887.5L8.9 875.0L9.4 862.5L9.0 850.0L4.6 837.5L1.4 825.0L13.8 821.8L0.4 812.5L12.0 803.6L0.9 800.0L8.0 791.2L-0.1 787.5L8.9 783.5L3.7 775.0L12.0 767.9L6.9 762.5L12.3 755.6L5.8 750.0L12.8 746.8L6.1 737.5L5.9 725.0L4.1 712.5L2.8 700.0L7.3 687.5L9.8 675.0L11.7 662.5L14.9 650.0L16.5 637.5L15.4 625.0L12.9 612.5L13.6 600.0Q90.4 603.9 153.2 626.0Q90.4 602.0 13.6 596.3L12.7 587.5L13.8 575.0L16.3 562.5L24.4 554.2L17.5 550.0L24.2 546.6L16.9 537.5L26.3 532.7L15.1 525.0L21.9 517.7L14.0 512.5L22.2 504.3L8.8 500.0L19.0 496.1L5.9 487.5L16.0 484.0L6.4 475.0L6.9 462.5L6.8 450.0L8.6 437.5L7.8 425.0L4.0 412.5L2.1 400.0L1.4 387.5L0.5 375.0L-1.0 362.5L2.6 350.0L5.9 337.5L7.0 325.0L6.9 312.5L8.0 300.0L5.0 287.5L4.6 275.0L7.8 262.5L9.7 250.0L12.1 237.5L16.1 225.0L18.2 212.5L16.2 200.0L14.8 187.5L14.9 175.0L12.9 162.5L11.9 150.0L14.6 137.5L16.2 125.0L16.5 112.5L15.1 100.0L13.6 87.5L9.6 75.0L6.0 62.5L5.6 50.0L5.7 37.5L4.6 25.0L6.6 12.5L7.3 0.0Z' fill='none' stroke='%233A1C08' stroke-width='6' stroke-opacity='.4' filter='url%28%23c%29'/%3E%3C/svg%3E") 0 0 / 100% 100% no-repeat; mix-blend-mode: multiply;
    }
    .sc .map__claw { display: inline; }
    .sc .map__claw .gouge { fill: #3A1E0A; opacity: .7; }
    .sc .map__claw .lip { fill: #FFF6DC; opacity: .85; }
    .sc .routemap__paper svg { position: relative; z-index: 2; }
    .sc .routemap__paper[style] {
        background:
            radial-gradient(120% 90% at 50% 50%, transparent 50%, rgba(95,52,15,.6) 100%),
            var(--paper) center / cover;
    }
    .sc .map__land { fill: rgba(196,140,62,.3); }
    .sc .map__dune { stroke: #7A4A1C; stroke-width: 1.3; }
    .sc .map__sand { opacity: .9; }
    .sc .map__cartouche text { font-size: 19px; }
    .sc .map__land { stroke: #4A2C14; stroke-width: 1.5; }
    .sc .map__grid { stroke: rgba(74,44,20,.22); }
    .sc .map__route { stroke: #B3261E; stroke-width: 4; stroke-dasharray: 10 8; }
    .sc .map__pin text, .sc .map__compass text { stroke: rgba(240,222,180,.85); stroke-width: 3px; fill: #2E1B0C; font-size: 17px; }
    .sc .map__cartouche text { font-size: 18px; font-style: normal; fill: #8E1F14; stroke: none; }
    .sc .map__cartouche .map__small { font-size: 11px; fill: #3A2616; letter-spacing: .2em; font-style: normal; text-transform: uppercase; }
    .sc .map__sea { font-family: var(--font-display); font-style: italic; font-size: 20px; letter-spacing: .5em; fill: rgba(46,27,12,.45); text-transform: uppercase; }
    .sc .map__x { stroke: #B3261E; stroke-width: 4.5; stroke-linecap: round; }
    .sc .map__traveller { fill: #FFE7A8; stroke: #8E1F14; filter: drop-shadow(0 0 6px rgba(255,200,90,.9)); }
    .map__sea, .map__x { display: none; }
    .sc .map__sea, .sc .map__x { display: inline; }
    .js .sc .reveal .map__x { opacity: 0; }
    .js .sc .reveal.in .map__x { opacity: 1; transition: opacity .4s ease 4.3s; }

    /* The canyon behind the map and the leader: warmer and less shaded than
       the closing bands, so it reads as a place rather than as a darkened
       picture. */
    .sc .section--map.section--photo .section__photo img,
    .sc .section--leader.section--photo .section__photo img { opacity: .85; filter: sepia(.35) saturate(1.15); }
    .sc .section--map.section--photo::after, .sc .section--leader.section--photo::after {
        background: linear-gradient(180deg, rgba(24,14,6,.78) 0%, rgba(24,14,6,.38) 45%, rgba(24,14,6,.82) 100%);
    }

    /* ---- Expedition leader: a dossier, not a staff photo --------------- */
    .sc .team--solo .person__photo {
        position: relative; background: #F4EEDD; padding: 14px 14px 58px; transform: rotate(-3deg);
        box-shadow: 0 30px 70px rgba(0,0,0,.55);
    }
    .sc .team--solo .person__photo::before {
        content: ''; position: absolute; z-index: 2; top: -14px; left: 50%; width: 130px; height: 32px; margin-left: -65px;
        background: rgba(236,221,186,.7); transform: rotate(-3deg); box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    .sc .team--solo .person__photo img { aspect-ratio: 4 / 5; object-fit: cover; width: 100%; filter: sepia(.25) contrast(1.05); }
    .sc .person__caption {
        display: block; position: absolute; left: 0; right: 0; bottom: 16px; text-align: center;
        font-family: var(--font-display); font-style: italic; font-size: 22px; color: #2E1B0C;
    }
    .sc .team--solo .person__role {
        display: inline-block; padding: 6px 14px; border: 3px double #C0392B; color: #C0392B;
        font-weight: 700; letter-spacing: .22em; text-transform: uppercase; transform: rotate(-4deg);
        opacity: .9; margin-bottom: var(--s4);
    }
    .sc .team--solo .person__name { font-size: clamp(36px, 4.6vw, 60px); line-height: 1; }
    .sc .person__facts { border-top: 1px dashed rgba(226,171,108,.4); padding-top: var(--s4); }
    .sc .person__facts div { font-family: 'Courier New', Courier, monospace; font-size: 16px; }
    .sc .person__facts dt { text-transform: uppercase; letter-spacing: .12em; color: #E2AB6C; min-width: 130px; }
    .sc .section--photo .person__text { color: rgba(255,255,255,.82); }
    .sc .section--photo .person__name { color: #fff; }
    .sc .section--photo .person__facts dd { color: #fff; }


    /* ---- Expedition look for the plain bands -------------------------------
       Contour lines on dark ground, notes pinned with tape, stamps. The same
       blocks as every site, dressed as a field journal. */
    .sc .section--highlights, .sc .section--about, .sc .section--itinerary, .sc .section--offers {
        background: radial-gradient(90% 70% at 20% 10%, rgba(120,78,38,.22), transparent 60%), radial-gradient(80% 60% at 85% 90%, rgba(90,55,25,.25), transparent 60%), url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.75' numOctaves='3' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .78  0 0 0 0 .6  0 0 0 0 .36  0 0 0 .09 0'/%3E%3C/filter%3E%3Crect width='100%' height='100%' filter='url%28%23g%29'/%3E%3C/svg%3E"), #1E1610;
        background-attachment: fixed; color: #F1E4C6;
    }
    .sc .section--highlights h2, .sc .section--about h2, .sc .section--itinerary h2, .sc .section--offers h2 { color: #F6E7C4; }
    .sc .section--highlights .rule, .sc .section--about .rule, .sc .section--itinerary .rule, .sc .section--offers .rule { border-color: rgba(226,171,108,.3); }
    .sc .section--highlights .rule__label, .sc .section--about .rule__label, .sc .section--itinerary .rule__label, .sc .section--offers .rule__label { color: #E2AB6C; }
    .sc .section--about .prose, .sc .section--about .prose p, .sc .section--offers .lead, .sc .section--itinerary .lead, .sc .section--itinerary .note { color: rgba(241,228,198,.8); }

    /* Field notes: paper, tape, a stamped number. */
    .sc .section--highlights .cards { counter-reset: note; gap: var(--s5); }
    .sc .section--highlights .card {
        counter-increment: note; position: relative; padding: var(--s5) var(--s4) var(--s4);
        background: linear-gradient(180deg, #F2E4C2, #E7D3A6); color: #2E1B0C; border: 0;
        box-shadow: 0 22px 40px rgba(0,0,0,.45); transform: rotate(-1deg);
    }
    .sc .section--highlights .card:nth-child(even) { transform: rotate(1.2deg); }
    .sc .section--highlights .card::before {
        content: ''; position: absolute; top: -12px; left: 50%; width: 90px; height: 24px; margin-left: -45px;
        background: rgba(236,221,186,.75); transform: rotate(-3deg); box-shadow: 0 1px 3px rgba(0,0,0,.25);
    }
    .sc .section--highlights .card::after {
        content: counter(note, decimal-leading-zero); position: absolute; top: 12px; right: 14px;
        padding: 2px 8px; border: 2px solid #B3261E; color: #B3261E; font-weight: 700; font-size: 12px;
        letter-spacing: .12em; transform: rotate(6deg); opacity: .85;
    }
    .sc .section--highlights .card h3 { font-family: 'Courier New', Courier, monospace; text-transform: uppercase; letter-spacing: .06em; font-size: 16px; color: #2E1B0C; }
    .sc .section--highlights .card p { color: #4A3520; }
    .sc .section--highlights .card__icon { color: #8E1F14; }

    /* About: the photograph as a print taped into the journal. */
    .sc .section--about .figure { position: relative; background: #F4EEDD; padding: 12px 12px 12px; transform: rotate(2deg); box-shadow: 0 30px 60px rgba(0,0,0,.5); }
    .sc .section--about .figure img { filter: sepia(.2) contrast(1.05); }
    .sc .section--about .figure::before {
        content: ''; position: absolute; z-index: 2; top: -14px; right: 18%; width: 110px; height: 28px;
        background: rgba(236,221,186,.75); transform: rotate(8deg); box-shadow: 0 1px 3px rgba(0,0,0,.25);
    }

    /* Tour cards: the length as a red stamp. */
    .sc .offer-card__meta {
        border-radius: 0; background: none; -webkit-backdrop-filter: none; backdrop-filter: none;
        border: 2px solid #E0503E; color: #FFD9C9; font-weight: 700; letter-spacing: .16em; transform: rotate(-3deg);
    }

    /* The day-by-day: days stamped, stops in the journal hand. */
    .sc .section--itinerary .trip__day { font-family: 'Courier New', Courier, monospace; color: #E2AB6C; font-weight: 700; }
    .sc .section--itinerary .trip__body h3 { color: #F6E7C4; }
    .sc .section--itinerary .trip__drive { color: #E2AB6C; font-family: 'Courier New', Courier, monospace; }
    .sc .section--itinerary .trip__text, .sc .section--itinerary .trip__stay { color: rgba(241,228,198,.8); }
    .sc .section--itinerary .trip__body::before { background: #1E1610; }

    /* The numbers: brass on leather. */
    .sc .stats { background: radial-gradient(90% 70% at 20% 10%, rgba(120,78,38,.22), transparent 60%), radial-gradient(80% 60% at 85% 90%, rgba(90,55,25,.25), transparent 60%), url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.75' numOctaves='3' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .78  0 0 0 0 .6  0 0 0 0 .36  0 0 0 .09 0'/%3E%3C/filter%3E%3Crect width='100%' height='100%' filter='url%28%23g%29'/%3E%3C/svg%3E"), #140E09; }
    .sc .stats dd { color: #EFCB8B; text-shadow: 0 2px 0 rgba(0,0,0,.5); }
    .sc .stats__item { border-left-color: rgba(226,171,108,.35); }

    /* ---- The scene around the handset ------------------------------------
       Real cut-outs, not drawings: the people from the business's own photos
       stand beside the video, its own vehicle is parked in front, both on one
       ground shadow. Wide screens only; a phone shows the video full screen. */
    .scene { display: none; }
    @media (min-width: 900px) {
        .sc .scene {
            display: block; position: absolute; z-index: 4; pointer-events: none;
            top: 44%; right: max(5vw, calc((100vw - var(--container)) / 2));
            width: clamp(238px, 22vw, 323px); aspect-ratio: 9 / 19; transform: translateY(-50%);
        }
        /* The ground the wheels stand on, just below the handset's edge. */
        .sc .scene::after {
            content: ''; position: absolute; z-index: -1; left: -55%; right: -35%; bottom: -21%; height: 8%;
            background: radial-gradient(closest-side, rgba(0,0,0,.7), transparent); filter: blur(6px);
        }
        .sc .scene img { position: absolute; max-width: none; filter: drop-shadow(0 18px 24px rgba(0,0,0,.45)); }
        /* The parked vehicle covers where the caption sat. */
        .sc .scene ~ .hero__videonote { display: none; }
        .sc .scene__front { width: 175%; left: -48%; bottom: -18%; animation: scPark 1.8s var(--ease-out) 1s both; }
        .sc .scene__side { height: 40%; left: -80%; bottom: -14%; animation: scUp 1.4s var(--ease-out) 1.4s both; }
    }
    @keyframes scPark { from { opacity: 0; transform: translateX(-60px); } to { opacity: 1; transform: none; } }
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
        .sc .scene img { animation: none; }
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

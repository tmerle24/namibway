{{--
    Styles for the two lodge-facing screens.

    Written as plain CSS rather than utility classes on purpose: this panel has
    no custom Filament theme, so the only Tailwind that exists in the built CSS
    is whatever Filament's own views happen to use. A dense grid needs widths,
    sticky offsets and a dozen states that are not in that set, and inventing
    class names that silently resolve to nothing is worse than writing the
    rules out.

    Colours come from the panel's own palette (Filament publishes --primary-*,
    --gray-*, --danger-* … as space-separated RGB channels), with literal
    fallbacks so the screen still reads if that ever changes. Dark mode follows
    Filament's .dark class on <html>.
--}}
<style>
    .nw-lodge {
        --nw-col-w: 42px;
        --nw-label-w: 190px;
        --nw-lane-h: 22px;
        --nw-surface: rgb(255 255 255);
        --nw-line: rgb(var(--gray-200, 229 231 235));
        --nw-line-strong: rgb(var(--gray-300, 209 213 219));
        --nw-muted: rgb(var(--gray-500, 107 114 128));
        --nw-text: rgb(var(--gray-950, 3 7 18));
        --nw-weekend: rgb(var(--gray-50, 249 250 251));
        --nw-past: rgb(var(--gray-100, 243 244 246));
        --nw-today: rgb(var(--primary-500, 181 101 29));
    }

    .dark .nw-lodge {
        --nw-surface: rgb(var(--gray-900, 17 24 39));
        --nw-line: rgb(255 255 255 / 0.1);
        --nw-line-strong: rgb(255 255 255 / 0.2);
        --nw-muted: rgb(var(--gray-400, 156 163 175));
        --nw-text: rgb(255 255 255);
        --nw-weekend: rgb(255 255 255 / 0.03);
        --nw-past: rgb(0 0 0 / 0.2);
    }

    /* ---------- toolbar ---------- */

    .nw-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem;
        justify-content: space-between;
    }

    .nw-toolbar__group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .nw-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: 1px solid var(--nw-line-strong);
        border-radius: 0.5rem;
        padding: 0.35rem 0.7rem;
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--nw-text);
        background: var(--nw-surface);
        cursor: pointer;
        white-space: nowrap;
    }

    .nw-btn:hover {
        border-color: var(--nw-today);
        color: var(--nw-today);
    }

    .nw-btn--primary {
        color: rgb(255 255 255);
        background: rgb(var(--primary-600, 163 91 26));
        border-color: rgb(var(--primary-600, 163 91 26));
    }

    .nw-btn--primary:hover {
        color: rgb(255 255 255);
        background: rgb(var(--primary-700, 136 76 22));
        border-color: rgb(var(--primary-700, 136 76 22));
    }

    .nw-btn[disabled] {
        opacity: 0.55;
        cursor: progress;
    }

    /* The row of things you can do to whatever the drawer is showing. Wraps,
       because a stay early in its life offers more moves than a late one and
       the drawer is narrow on a tablet. */
    .nw-drawer__actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1rem;
        padding-top: 0.85rem;
        border-top: 1px solid var(--nw-line);
    }

    .nw-range {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--nw-text);
    }

    .nw-hint {
        font-size: 0.8125rem;
        color: var(--nw-muted);
    }

    /* ---------- legend ---------- */

    .nw-legend {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem 1.25rem;
        font-size: 0.75rem;
        color: var(--nw-muted);
    }

    .nw-legend__item {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .nw-legend__swatch {
        display: inline-block;
        width: 1.75rem;
        height: 0.85rem;
        border-radius: 0.25rem;
        border: 1px solid var(--nw-line-strong);
    }

    /* ---------- the grid ---------- */

    /* Spacing lives here rather than inline, because the grid is now shared by
       the Calendar page and the dashboard widget. */
    .nw-cal {
        margin-top: 1rem;
    }

    /* On the dashboard the grid is one card among several, so its ceiling is a
       fixed height rather than the window — a widget that fills the screen is
       not a widget — and its rows sit tighter than on the full page. Sizing to
       content is the shared behaviour now, so only the two limits differ. */
    .nw-lodge--widget .nw-cal__viewport {
        min-height: 0;
        max-height: 24rem;
    }

    .nw-lodge--widget .nw-cal__row {
        min-height: 2.5rem;
    }

    /* The grid takes the width it is given, but only the height it needs.

       The window minus the chrome above and below it is a *ceiling*, not a
       height: a lodge with three room types got three ~190px rows carrying
       ~110px of empty space each, which reads as a rendering fault rather
       than as a spacious calendar. As a max the frame ends under the last
       room type when there are few, and scrolls when there are many.

       Below a laptop-sized window it stops shrinking and scrolls instead,
       because a calendar squeezed into 200px is not a calendar. */
    .nw-cal__viewport {
        max-height: calc(100vh - 19rem);
        min-height: 22rem;
        overflow: auto;
        border: 1px solid var(--nw-line);
        border-radius: 0.75rem;
        background: var(--nw-surface);
    }

    .nw-cal__table {
        display: flex;
        flex-direction: column;
        width: 100%;
        min-width: max-content;
        font-size: 0.75rem;
        color: var(--nw-text);
    }

    .nw-cal__head,
    .nw-cal__row,
    .nw-cal__foot {
        display: flex;
        align-items: stretch;
    }

    .nw-cal__head,
    .nw-cal__foot {
        flex: 0 0 auto;
    }

    .nw-cal__row {
        /* As tall as the bars stacked in it, and no taller. Deliberately not a
           max-height: lane packing is unbounded — a room type with eight units
           can stack eight lanes — so a cap would hide real bookings. The floor
           keeps a room type with none from collapsing onto its own label. */
        flex: 0 0 auto;
        min-height: 3.5rem;
    }

    .nw-cal__head {
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--nw-surface);
        border-bottom: 1px solid var(--nw-line-strong);
    }

    .nw-cal__foot {
        position: sticky;
        bottom: 0;
        z-index: 3;
        background: var(--nw-surface);
        border-top: 1px solid var(--nw-line-strong);
    }

    .nw-cal__row + .nw-cal__row {
        border-top: 1px solid var(--nw-line);
    }

    .nw-cal__label {
        position: sticky;
        left: 0;
        z-index: 2;
        flex: 0 0 var(--nw-label-w);
        width: var(--nw-label-w);
        padding: 0.4rem 0.6rem;
        background: var(--nw-surface);
        border-right: 1px solid var(--nw-line-strong);
    }

    .nw-cal__name {
        font-weight: 600;
        line-height: 1.2;
    }

    .nw-cal__meta {
        margin-top: 0.15rem;
        font-size: 0.6875rem;
        color: var(--nw-muted);
    }

    .nw-cal__retired {
        opacity: 0.65;
    }

    .nw-cal__days,
    .nw-cal__lane,
    .nw-cal__cells {
        display: grid;
        grid-template-columns: repeat(var(--nw-cols), minmax(var(--nw-col-w), 1fr));
    }

    /* The night columns take everything the label column does not. */
    .nw-cal__body,
    .nw-cal__head .nw-cal__days,
    .nw-cal__foot .nw-cal__days {
        flex: 1 1 auto;
        min-width: 0;
    }

    /* Cells fill their row so a tall row does not leave a gap under them. */
    .nw-cal__body {
        display: flex;
        flex-direction: column;
    }

    .nw-cal__cells {
        flex: 1 1 auto;
    }

    .nw-cal__dayhead {
        padding: 0.3rem 0 0.35rem;
        text-align: center;
        line-height: 1.15;
        border-left: 1px solid var(--nw-line);
    }

    .nw-cal__dayhead--weekend,
    .nw-cal__cell--weekend {
        background: var(--nw-weekend);
    }

    .nw-cal__dayhead--past,
    .nw-cal__cell--past {
        background: var(--nw-past);
    }

    .nw-cal__dayhead--month {
        border-left: 2px solid var(--nw-line-strong);
    }

    .nw-cal__dayhead--today {
        box-shadow: inset 0 -2px 0 0 var(--nw-today);
        font-weight: 700;
    }

    .nw-cal__dow {
        font-size: 0.625rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: var(--nw-muted);
    }

    .nw-cal__dom {
        font-weight: 600;
    }

    .nw-cal__month {
        padding: 0.25rem 0.6rem;
        font-weight: 600;
        font-size: 0.6875rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--nw-muted);
    }

    .nw-cal__body {
        position: relative;
    }

    .nw-cal__lanes {
        padding: 0.25rem 0 0.15rem;
    }

    .nw-cal__lane {
        height: var(--nw-lane-h);
        align-items: center;
    }

    .nw-cal__lane + .nw-cal__lane {
        margin-top: 2px;
    }

    /* ---------- bars ---------- */

    .nw-bar {
        grid-row: 1;
        display: block;
        width: calc(100% - 4px);
        margin: 0 2px;
        height: calc(var(--nw-lane-h) - 2px);
        line-height: calc(var(--nw-lane-h) - 4px);
        padding: 0 0.35rem;
        border-radius: 0.3rem;
        border: 1px solid transparent;
        font-size: 0.6875rem;
        font-weight: 500;
        text-align: left;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
        color: rgb(255 255 255);
    }

    .nw-bar--clipped-start {
        border-start-start-radius: 0;
        border-end-start-radius: 0;
        border-inline-start: 2px dashed rgb(255 255 255 / 0.7);
    }

    .nw-bar--clipped-end {
        border-start-end-radius: 0;
        border-end-end-radius: 0;
        border-inline-end: 2px dashed rgb(255 255 255 / 0.7);
    }

    .nw-bar--primary { background: rgb(var(--primary-600, 163 91 26)); }
    .nw-bar--success { background: rgb(var(--success-600, 22 163 74)); }
    .nw-bar--warning { background: rgb(var(--warning-500, 245 158 11)); color: rgb(69 26 3); }
    .nw-bar--info { background: rgb(var(--info-600, 37 99 235)); }
    .nw-bar--danger { background: rgb(var(--danger-600, 220 38 38)); }
    .nw-bar--gray { background: rgb(var(--gray-500, 107 114 128)); }

    /*
        A block is not a sale, so it must not read as one at a glance: no
        colour, a hatched fill and a dashed outline. That difference has to
        survive a monochrome print and a colour-blind reader, which is why it
        is texture and not hue.
    */
    .nw-bar--block {
        cursor: pointer;
        color: var(--nw-text);
        border: 1px dashed var(--nw-line-strong);
        background-color: transparent;
        background-image: repeating-linear-gradient(
            45deg,
            rgb(var(--gray-400, 156 163 175) / 0.45) 0,
            rgb(var(--gray-400, 156 163 175) / 0.45) 3px,
            transparent 3px,
            transparent 7px
        );
    }

    /* ---------- cells ---------- */

    .nw-cal__cells {
        border-top: 1px dashed var(--nw-line);
    }

    /* A cell is a <button>, because clicking a free night is how a booking
       starts. Everything a button brings with it has to be undone first —
       the colour, the font, the padding — or the grid stops being a grid. */
    .nw-cell {
        position: relative;
        display: block;
        width: 100%;
        appearance: none;
        -webkit-appearance: none;
        margin: 0;
        padding: 0.2rem 0.1rem 0.25rem;
        text-align: center;
        font: inherit;
        color: inherit;
        background: none;
        border: 0;
        border-left: 1px solid var(--nw-line);
        border-radius: 0;
        line-height: 1.15;
        cursor: default;
    }

    .nw-cell--bookable {
        cursor: pointer;
    }

    .nw-cell--bookable:hover {
        background: rgb(var(--primary-500, 181 101 29) / 0.14);
        box-shadow: inset 0 0 0 1px rgb(var(--primary-500, 181 101 29) / 0.55);
    }

    .nw-cell--bookable:focus-visible {
        outline: 2px solid rgb(var(--primary-600, 163 91 26));
        outline-offset: -2px;
    }

    /* A sold-out night is a disabled button; it must not look faded, because
       the number in it is exactly what somebody came to read. */
    .nw-cell:disabled {
        opacity: 1;
    }

    .nw-cell--month {
        border-left: 2px solid var(--nw-line-strong);
    }

    .nw-cell__free {
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    .nw-cell__rate {
        font-size: 0.625rem;
        color: var(--nw-muted);
        font-variant-numeric: tabular-nums;
    }

    .nw-cell--soldout {
        background: rgb(var(--danger-500, 239 68 68) / 0.12);
    }

    .nw-cell--soldout .nw-cell__free {
        color: rgb(var(--danger-700, 185 28 28));
    }

    .dark .nw-cell--soldout .nw-cell__free {
        color: rgb(var(--danger-400, 248 113 113));
    }

    .nw-cell--overbooked {
        background: rgb(var(--danger-600, 220 38 38));
        color: rgb(255 255 255);
    }

    .nw-cell--overbooked .nw-cell__free,
    .nw-cell--overbooked .nw-cell__rate {
        color: rgb(255 255 255);
    }

    /*
        Restrictions have to be visible without shouting: a corner wedge for a
        closed day, a small number for a minimum stay. Both carry a title so
        the meaning is one hover away rather than in a legend nobody reads.
    */
    .nw-cell__cta,
    .nw-cell__ctd {
        position: absolute;
        top: 0;
        width: 0;
        height: 0;
        border-top: 7px solid rgb(var(--danger-500, 239 68 68));
    }

    .nw-cell__cta {
        left: 0;
        border-right: 7px solid transparent;
    }

    .nw-cell__ctd {
        right: 0;
        border-left: 7px solid transparent;
    }

    .nw-cell__minstay {
        position: absolute;
        bottom: 1px;
        right: 2px;
        font-size: 0.5625rem;
        font-weight: 700;
        color: rgb(var(--warning-600, 217 119 6));
    }

    .nw-cal__total {
        padding: 0.35rem 0 0.4rem;
        text-align: center;
        border-left: 1px solid var(--nw-line);
        font-variant-numeric: tabular-nums;
    }

    .nw-cal__total--full {
        color: rgb(var(--danger-600, 220 38 38));
        font-weight: 700;
    }

    /* ---------- detail drawer ---------- */

    .nw-drawer {
        position: fixed;
        inset: 0;
        z-index: 40;
        display: flex;
        justify-content: flex-end;
        background: rgb(0 0 0 / 0.4);
    }

    .nw-drawer__panel {
        width: min(28rem, 100%);
        height: 100%;
        overflow-y: auto;
        padding: 1.25rem;
        background: var(--nw-surface);
        color: var(--nw-text);
        box-shadow: -8px 0 24px rgb(0 0 0 / 0.15);
    }

    .nw-drawer__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .nw-drawer__title {
        font-size: 1.0625rem;
        font-weight: 600;
    }

    .nw-facts {
        display: grid;
        grid-template-columns: 9rem 1fr;
        gap: 0.4rem 1rem;
        font-size: 0.8125rem;
    }

    .nw-facts dt {
        color: var(--nw-muted);
    }

    .nw-facts dd {
        margin: 0;
    }

    .nw-subhead {
        margin: 1.25rem 0 0.5rem;
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--nw-muted);
    }

    /* ---------- plain tables (arrivals, stay lines) ---------- */

    .nw-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8125rem;
        color: var(--nw-text);
    }

    .nw-table th {
        text-align: start;
        font-weight: 600;
        font-size: 0.6875rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--nw-muted);
        padding: 0.4rem 0.6rem;
        border-bottom: 1px solid var(--nw-line-strong);
        white-space: nowrap;
    }

    .nw-table td {
        padding: 0.5rem 0.6rem;
        border-bottom: 1px solid var(--nw-line);
        vertical-align: top;
    }

    .nw-table tbody tr:hover {
        background: var(--nw-weekend);
    }

    .nw-table__link {
        font-weight: 600;
        color: var(--nw-today);
        text-align: start;
        cursor: pointer;
        background: none;
        border: 0;
        padding: 0;
    }

    .nw-num {
        font-variant-numeric: tabular-nums;
    }

    .nw-badge {
        display: inline-block;
        padding: 0.1rem 0.45rem;
        border-radius: 999px;
        font-size: 0.6875rem;
        font-weight: 600;
        border: 1px solid currentColor;
        white-space: nowrap;
    }

    .nw-badge--primary { color: rgb(var(--primary-700, 136 76 22)); }
    .nw-badge--success { color: rgb(var(--success-700, 21 128 61)); }
    .nw-badge--warning { color: rgb(var(--warning-700, 180 83 9)); }
    .nw-badge--info { color: rgb(var(--info-700, 29 78 216)); }
    .nw-badge--danger { color: rgb(var(--danger-700, 185 28 28)); }
    .nw-badge--gray { color: var(--nw-muted); }

    .dark .nw-badge--primary { color: rgb(var(--primary-400, 203 147 97)); }
    .dark .nw-badge--success { color: rgb(var(--success-400, 74 222 128)); }
    .dark .nw-badge--warning { color: rgb(var(--warning-400, 251 191 36)); }
    .dark .nw-badge--info { color: rgb(var(--info-400, 96 165 250)); }
    .dark .nw-badge--danger { color: rgb(var(--danger-400, 248 113 113)); }

    .nw-empty {
        padding: 1rem 0.6rem;
        font-size: 0.8125rem;
        color: var(--nw-muted);
    }

    .nw-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        font-size: 0.8125rem;
        color: var(--nw-muted);
    }

    .nw-stats__value {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--nw-text);
        line-height: 1.1;
    }

    /* ---------- print ---------- */

    @media print {
        .nw-noprint,
        .nw-drawer {
            display: none !important;
        }

        /* On paper there is no window to cap against, so the ceiling comes off
           entirely — leaving it on would print one screenful and cut the rest.
           Rows lose their floor too: paper has no empty frame to look sparse
           in, so a room type only takes the height its bars need. */
        .nw-cal__viewport {
            min-height: 0;
            max-height: none;
            overflow: visible;
            border: 0;
        }

        .nw-cal__row {
            min-height: 0;
        }

        .nw-table tbody tr {
            break-inside: avoid;
        }

        .nw-lodge {
            --nw-surface: #fff;
            --nw-text: #000;
            --nw-muted: #444;
            --nw-line: #ccc;
            --nw-line-strong: #999;
        }
    }

    /* Tablet: the label column costs the most, so it gives ground first. */
    @media (max-width: 1024px) {
        .nw-lodge {
            --nw-col-w: 38px;
            --nw-label-w: 140px;
        }
    }
</style>

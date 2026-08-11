{{--
    Printing, for the whole panel.

    The bug this fixes, recorded in BOOKING_SYSTEM.md: printing the arrivals
    board produced the navigation and none of the board. The print rules this
    codebase had written — `nw-noprint`, the calendar viewport's ceiling coming
    off, table rows not breaking across pages — all assume the *page* prints and
    only some parts of it need hiding. None of them touch Filament's own shell,
    and the shell is the problem: the sidebar and topbar are fixed/positioned
    elements, and the main content sits in a scrolling container. On paper the
    shell is what has a position and the content is what gets clipped.

    So this lives on the panel rather than on a screen. Every page a property
    prints has the same problem, and a printed arrivals list is carried around
    the property while a printed calendar goes on the office wall.

    Nothing here is a Filament internal we are guessing at: `fi-sidebar`,
    `fi-topbar` and `fi-main` are the panel's own public layout classes, and the
    rules only undo positioning and hiding — a class that disappears in some
    later version leaves the page printing as it does today rather than breaking
    it.
--}}
<style>
    @media print {
        /* The shell: navigation, header chrome and anything that only exists to
           be clicked. None of it means anything on paper. */
        .fi-sidebar,
        .fi-topbar,
        .fi-sidebar-close-overlay,
        .fi-main-ctn > .fi-header .fi-header-actions,
        .fi-btn,
        .fi-modal,
        .fi-no-print {
            display: none !important;
        }

        /* The content region is laid out around a sidebar that is no longer
           there, and scrolls inside a container that paper does not have.

           `.fi-header` is in this list for a reason worth writing down: this
           panel makes the page header **sticky** (see the sticky-page-header
           partial), and a sticky element on paper is one that has left the
           flow — it printed on top of the first line of the board, so the
           date the sheet is for vanished under the page title. A passenger
           list carried to a vehicle with no date on it is worse than no sheet,
           and it was invisible on screen. */
        .fi-body,
        .fi-layout,
        .fi-main-ctn,
        .fi-main,
        .fi-header {
            display: block !important;
            position: static !important;
            overflow: visible !important;
            width: auto !important;
            max-width: none !important;
            height: auto !important;
            max-height: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* The header keeps a little air under it — it is the title of the
           sheet, not the first row of the table. */
        .fi-header {
            margin-bottom: 0.75rem !important;
            background: none !important;
        }

        /* A section is the unit a page should break between, not through. */
        .fi-section {
            break-inside: avoid;
            box-shadow: none !important;
            border: 0 !important;
        }

        /* Ink, and the paper's own background. A dark-mode screen prints as a
           black page otherwise, which is both unreadable and a cartridge. */
        html,
        body,
        .fi-body {
            background: #fff !important;
            color: #000 !important;
        }
    }
</style>

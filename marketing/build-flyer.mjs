#!/usr/bin/env node
/**
 * Renders the A4 print material to print-ready PDFs with headless Chromium.
 *
 *   node marketing/build-flyer.mjs              # everything
 *   node marketing/build-flyer.mjs websites     # only ones whose name matches
 *
 * Two kinds of piece are built by the same loop, because they are the same design
 * and differ only in length: the two-page flyers, and the multi-page concept
 * documents that explain one product line each. The script name is historical —
 * it predates the documents, and renaming it would break every note that says how
 * to rebuild a flyer.
 *
 * Each piece produces two variants:
 *   *-print.pdf   216 x 303 mm — A4 plus 3 mm bleed on every side, no crop marks.
 *                 This is what online print shops ask for. Full-bleed elements
 *                 (the brown bands, the amber call to action) run into the bleed.
 *   *-screen.pdf  210 x 297 mm — plain A4 for email and the office printer.
 *
 * No npm dependencies: it drives Chromium through `--print-to-pdf`, which honours
 * the CSS `@page` size, and rewrites that one rule per variant.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const outDir = join(here, 'out');

const PIECES = [
    // Flyers — two pages, front and back.
    { source: 'flyer-partners-a4.html', out: 'namibway-partner-flyer-a4' },
    { source: 'flyer-websites-a4.html', out: 'namibway-websites-flyer-a4' },
    { source: 'flyer-booking-system-a4.html', out: 'namibway-booking-system-flyer-a4' },

    // Concept documents — the overview, then one per product line.
    { source: 'concept-overview.html', out: 'namibway-concept-overview-a4' },
    { source: 'concept-kaia-trip-planning.html', out: 'namibway-concept-kaia-trip-planning-a4' },
    { source: 'concept-booking-payment.html', out: 'namibway-concept-booking-payment-a4' },
    { source: 'concept-websites.html', out: 'namibway-concept-websites-a4' },
];

const VARIANTS = [
    { suffix: 'print', page: '@page { size: 216mm 303mm; margin: 0; }', attr: 'data-variant="print"' },
    { suffix: 'screen', page: '@page { size: 210mm 297mm; margin: 0; }', attr: 'data-variant="screen"' },
];

const CHROME_CANDIDATES = [
    process.env.CHROME_BIN,
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);

const chrome = CHROME_CANDIDATES.find((candidate) => existsSync(candidate));

if (!chrome) {
    console.error('No Chromium found. Set CHROME_BIN to a Chrome or Chromium binary.');
    process.exit(1);
}

const pageRule = /@page \{[^}]*\} \/\* PAGESIZE \*\//;
const filter = process.argv[2];
const selected = filter ? PIECES.filter((f) => f.source.includes(filter)) : PIECES;

if (selected.length === 0) {
    console.error(`Nothing matches "${filter}".`);
    process.exit(1);
}

mkdirSync(outDir, { recursive: true });

for (const flyer of selected) {
    const html = readFileSync(join(here, flyer.source), 'utf8');

    for (const variant of VARIANTS) {
        // The temp file lives beside the source so relative asset paths still resolve.
        const tmp = join(here, `.build-${flyer.out}-${variant.suffix}.html`);
        const marked = `${variant.page} /* PAGESIZE */`;

        writeFileSync(
            tmp,
            html.replace(pageRule, marked).replace('data-variant="print"', variant.attr),
        );

        const pdf = join(outDir, `${flyer.out}-${variant.suffix}.pdf`);

        try {
            execFileSync(
                chrome,
                [
                    '--headless',
                    '--no-sandbox',
                    '--disable-gpu',
                    '--no-pdf-header-footer',
                    `--print-to-pdf=${pdf}`,
                    tmp,
                ],
                { stdio: ['ignore', 'ignore', 'pipe'] },
            );
        } finally {
            rmSync(tmp, { force: true });
        }

        console.log(resolve(pdf));
    }
}

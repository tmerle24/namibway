#!/usr/bin/env node
/**
 * Renders the A4 partner flyer to print-ready PDFs with headless Chromium.
 *
 *   node marketing/build-flyer.mjs
 *
 * Two variants come out of the one source file:
 *   *-print.pdf   216 x 303 mm — A4 plus 3 mm bleed on every side, no crop marks.
 *                 This is what online print shops ask for. Full-bleed elements
 *                 (the brown bands, the amber call to action) run into the bleed.
 *   *-screen.pdf  210 x 297 mm — plain A4 for email and the office printer.
 *
 * No npm dependencies: it drives the Chromium that ships with this container
 * through `--print-to-pdf`, which honours the CSS `@page` size.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const source = join(here, 'flyer-a4.html');
const outDir = join(here, 'out');

const CHROME_CANDIDATES = [
    process.env.CHROME_BIN,
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);

const chrome = CHROME_CANDIDATES.find((p) => existsSync(p));
if (!chrome) {
    console.error('No Chromium found. Set CHROME_BIN to a Chrome or Chromium binary.');
    process.exit(1);
}

const html = readFileSync(source, 'utf8');
mkdirSync(outDir, { recursive: true });

/** Both variants render from a temp file beside the source, so relative asset paths hold. */
const variants = [
    {
        name: 'namibway-partner-flyer-a4-print',
        page: '@page { size: 216mm 303mm; margin: 0; } /* PAGESIZE */',
        attr: 'data-variant="print"',
    },
    {
        name: 'namibway-partner-flyer-a4-screen',
        page: '@page { size: 210mm 297mm; margin: 0; } /* PAGESIZE */',
        attr: 'data-variant="screen"',
    },
];

const pageRule = /@page \{[^}]*\} \/\* PAGESIZE \*\//;

for (const variant of variants) {
    const tmp = join(here, `.build-${variant.name}.html`);
    writeFileSync(
        tmp,
        html.replace(pageRule, variant.page).replace('data-variant="print"', variant.attr),
    );

    const pdf = join(outDir, `${variant.name}.pdf`);
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

    console.log(`${resolve(pdf)}`);
}

#!/usr/bin/env node
/**
 * Fails when a page of the print material is silently clipping.
 *
 *   node marketing/check-pages.mjs            # everything
 *   node marketing/check-pages.mjs concept    # only ones whose name matches
 *
 * Why this exists rather than the snippet in marketing/README.md. That snippet
 * measures each page child's own scrollHeight, which catches a child whose content
 * is taller than the box it was given. It does NOT catch the failure these
 * documents actually have: `.page` is a flex column and `.doc-body` is `flex: 1`,
 * so its automatic minimum size is its content — overlong copy does not overflow
 * that box, it PUSHES THE FOOTER off the bottom of the page, where
 * `.page { overflow: hidden }` eats it without a trace. The page reports a perfect
 * fit and the PDF is missing its page number and half a paragraph.
 *
 * So this measures position, not just overflow: nothing that is a child of a page
 * may end up past the trim edge. The decorative compass on a cover deliberately
 * does, and is exempted by class.
 *
 * Both variants are checked. They are not the same test — the screen variant is
 * 6 mm SHORTER (no bleed) and therefore the tighter one vertically, while the
 * print variant has 3 mm less usable width on each side.
 *
 * It works by injecting the measurement into a copy of the page and reading the
 * answer back out of Chromium's `--dump-dom`, so it needs no debugging port and no
 * npm dependency — the same trade the build script makes.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const SOURCES = [
    'flyer-partners-a4.html',
    'flyer-websites-a4.html',
    'flyer-booking-system-a4.html',
    'concept-overview.html',
    'concept-kaia-trip-planning.html',
    'concept-booking-payment.html',
    'concept-websites.html',
];

/** Absolutely positioned ornament that is meant to run off the page. */
const EXEMPT = 'compass';

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

/* Runs in the page. Kept as a string so it can be injected verbatim. */
const PROBE = `
<script>
(function () {
    var MM = 3.7795;
    var EXEMPT = ${JSON.stringify(EXEMPT)};

    function measure(variant) {
        document.documentElement.dataset.variant = variant;
        void document.body.offsetHeight;

        var findings = [];

        [].forEach.call(document.querySelectorAll('.page'), function (page, index) {
            var pr = page.getBoundingClientRect();
            var slack = Infinity;

            [].forEach.call(page.children, function (child) {
                if (child.classList.contains(EXEMPT)) {
                    return;
                }

                var cr = child.getBoundingClientRect();
                var name = child.className || child.tagName.toLowerCase();

                if (cr.bottom - pr.bottom > 1) {
                    findings.push(
                        'page ' + (index + 1) + ' — .' + name + ' ends ' +
                        ((cr.bottom - pr.bottom) / MM).toFixed(1) + ' mm past the bottom trim edge'
                    );
                }

                if (cr.right - pr.right > 1) {
                    findings.push(
                        'page ' + (index + 1) + ' — .' + name + ' ends ' +
                        ((cr.right - pr.right) / MM).toFixed(1) + ' mm past the right trim edge'
                    );
                }

                var spill = child.scrollHeight - child.clientHeight;

                if (spill > 2) {
                    findings.push(
                        'page ' + (index + 1) + ' — .' + name + ' has ' +
                        (spill / MM).toFixed(1) + ' mm of content taller than its own box'
                    );
                }

                slack = Math.min(slack, (pr.bottom - cr.bottom) / MM);
            });

            /* On a document page the interesting number is not the gap under the
               last page child — .doc-body is flex: 1 and always fills it, so
               that gap reads 0.0 whether the page is comfortable or 30 mm over.
               What is worth reporting is the air left INSIDE the body, under its
               own last block. That is the margin a font substitution eats. */
            var body = page.querySelector('.doc-body');

            if (body && body.lastElementChild) {
                var br = body.getBoundingClientRect();
                var lastBlock = body.lastElementChild.getBoundingClientRect();
                slack = (br.bottom - lastBlock.bottom) / MM;
            }

            findings.push('~slack page ' + (index + 1) + ': ' + slack.toFixed(1) + ' mm');
        });

        /* A stretched logo is the same class of failure as a clipped page: nothing
           errors, nothing is missing, and it goes to a printer looking wrong. The
           usual cause here is an <img> that is a direct child of a flex column,
           where align-self: stretch overrides the intrinsic aspect ratio. */
        [].forEach.call(document.querySelectorAll('img'), function (img) {
            if (!img.naturalWidth || !img.naturalHeight) {
                findings.push('image did not load: ' + img.getAttribute('src'));

                return;
            }

            var box = img.getBoundingClientRect();

            if (box.width < 1 || box.height < 1) {
                return;
            }

            var drift = (box.width / box.height) / (img.naturalWidth / img.naturalHeight);

            if (drift < 0.98 || drift > 1.02) {
                findings.push(
                    'distorted image: ' + img.getAttribute('src') + ' is drawn ' +
                    Math.round((drift - 1) * 100) + '% off its own aspect ratio'
                );
            }
        });

        return findings.map(function (line) { return variant + ' · ' + line; });
    }

    /* Must wait for load, not run inline. Measuring before the images have decoded
       reports every one of them as "did not load" and, worse, measures a layout
       whose pictures still have no intrinsic size — so the answer is wrong in the
       direction that says everything fits. Chromium's --virtual-time-budget holds
       the dump open long enough for this to resolve. */
    function report() {
        var result = measure('print').concat(measure('screen'));
        var box = document.createElement('div');
        box.id = '__pagecheck';
        box.textContent = JSON.stringify(result);
        document.body.appendChild(box);
    }

    Promise.resolve(document.fonts ? document.fonts.ready : null).then(function () {
        if (document.readyState === 'complete') {
            report();

            return;
        }

        window.addEventListener('load', report);
    });
})();
</script>
`;

const filter = process.argv[2];
const selected = filter ? SOURCES.filter((s) => s.includes(filter)) : SOURCES;

if (selected.length === 0) {
    console.error(`Nothing matches "${filter}".`);
    process.exit(1);
}

let failed = false;

for (const source of selected) {
    const html = readFileSync(join(here, source), 'utf8');
    // Beside the source, so relative stylesheet and image paths still resolve.
    const tmp = join(here, `.check-${source}`);

    writeFileSync(tmp, html.replace('</body>', `${PROBE}</body>`));

    let dom;

    try {
        dom = execFileSync(
            chrome,
            [
                '--headless',
                '--no-sandbox',
                '--disable-gpu',
                '--virtual-time-budget=4000',
                '--dump-dom',
                tmp,
            ],
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 64 * 1024 * 1024 },
        );
    } finally {
        rmSync(tmp, { force: true });
    }

    const match = dom.match(/<div id="__pagecheck">(.*?)<\/div>/s);

    if (!match) {
        console.error(`${source}: the probe did not run — nothing to report, which is not the same as nothing wrong.`);
        failed = true;
        continue;
    }

    const lines = JSON.parse(
        match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>'),
    );
    const problems = lines.filter((line) => !line.includes('~slack'));
    const slack = lines.filter((line) => line.includes('~slack') && line.startsWith('screen'));

    // The screen variant is the tighter one, so its slack is the number that
    // matters. Leave a few millimetres: font substitution on another machine moves
    // this, and a page that fits exactly here can clip there.
    const room = slack.map((line) => line.replace('screen · ~slack ', '')).join(' · ');

    if (problems.length === 0) {
        console.log(`✓ ${source}`);
        console.log(`  ${room}`);
        continue;
    }

    failed = true;
    console.error(`✗ ${source}`);
    problems.forEach((line) => console.error(`  ${line}`));
    console.error(`  ${room}`);
}

process.exit(failed ? 1 : 0);

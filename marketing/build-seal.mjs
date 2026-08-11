#!/usr/bin/env node
/**
 * Generates the round "in Namibia" seal in two colourways.
 *
 *   node marketing/build-seal.mjs
 *
 * The compass in the middle is lifted out of the brand mark itself rather than
 * redrawn, so the seal cannot drift out of step with the logo: change the logo
 * and re-run this, and the seal follows.
 *
 * The wording is one constant below, because it is the thing most likely to be
 * argued about. "Built in Namibia" says software was made here; "Made in
 * Namibia" is the older country-of-origin phrasing and reads as manufactured
 * goods — and is close enough to the local buy-local campaign's wording that a
 * stamp using it can look like a certification we have not applied for.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const RING_TEXT = 'BUILT IN NAMIBIA';
const FOOT_TEXT = 'NAMIBWAY';

/** The mark is 950 units square; the seal is 200 and gives it a 68-unit well. */
const MARK_SIZE = 68;
const MARK_SCALE = MARK_SIZE / 950;
const MARK_OFFSET = 100 - MARK_SIZE / 2;

const source = readFileSync(join(here, 'assets/compass-on-sand.svg'), 'utf8');
const marks = [...source.matchAll(/<path\s+d="([^"]+)"[^>]*fill="([^"]+)"/g)].map((m) => m[1]);

if (marks.length !== 2) {
    console.error(`Expected 2 paths in the compass mark, found ${marks.length}.`);
    process.exit(1);
}

const [markBody, markNeedle] = marks;

/** A five-pointed star, used as the separator at three and nine o'clock. */
function star(cx, cy, r, fill) {
    const points = [];

    for (let i = 0; i < 10; i += 1) {
        const radius = i % 2 === 0 ? r : r * 0.42;
        const angle = (Math.PI / 5) * i - Math.PI / 2;

        points.push(`${(cx + radius * Math.cos(angle)).toFixed(2)},${(cy + radius * Math.sin(angle)).toFixed(2)}`);
    }

    return `<polygon points="${points.join(' ')}" fill="${fill}"/>`;
}

function seal({ ink, accent, markFill, needleFill }) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200" role="img" aria-label="${RING_TEXT}">
  <title>${RING_TEXT}</title>
  <defs>
    <path id="ringTop" d="M 100,100 m -71,0 a 71,71 0 1,1 142,0" fill="none"/>
    <path id="ringFoot" d="M 100,100 m -71,0 a 71,71 0 1,0 142,0" fill="none"/>
  </defs>

  <circle cx="100" cy="100" r="95" fill="none" stroke="${ink}" stroke-width="5"/>
  <circle cx="100" cy="100" r="85.5" fill="none" stroke="${ink}" stroke-width="1.6"/>
  <g font-family="Bitstream Charter, Charter, DejaVu Serif, Georgia, serif" font-weight="bold" fill="${ink}">
    <text font-size="16.5" letter-spacing="0.9">
      <textPath href="#ringTop" startOffset="50%" text-anchor="middle">${RING_TEXT}</textPath>
    </text>
    <text font-size="11.5" letter-spacing="5.2" fill="${accent}">
      <textPath href="#ringFoot" startOffset="50%" text-anchor="middle">${FOOT_TEXT}</textPath>
    </text>
  </g>

  ${star(21, 100, 5.6, accent)}
  ${star(179, 100, 5.6, accent)}

  <g transform="translate(${MARK_OFFSET.toFixed(3)},${MARK_OFFSET.toFixed(3)}) scale(${MARK_SCALE.toFixed(6)})">
    <path d="${markBody}" fill="${markFill}"/>
    <path d="${markNeedle}" fill="${needleFill}"/>
  </g>
</svg>
`;
}

const variants = [
    {
        file: 'assets/built-in-namibia-seal.svg',
        // For paper, sand and any light ground.
        colours: { ink: '#3B2418', accent: '#B9812E', markFill: '#B9812E', needleFill: '#F5F0E3' },
    },
    {
        file: 'assets/built-in-namibia-seal-light.svg',
        // For the brown band.
        colours: { ink: '#F3E7D3', accent: '#E2AB6C', markFill: '#E2AB6C', needleFill: '#3B2418' },
    },
];

for (const variant of variants) {
    const path = join(here, variant.file);

    writeFileSync(path, seal(variant.colours));
    console.log(path);
}

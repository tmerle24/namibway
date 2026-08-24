# Fonts

The three families the site is set in, as the exact files it serves.

They live here rather than being fetched from a font CDN at build time. The
Vite plugin self-hosts either way — it emits the files into the bundle, so
nothing ever reached a CDN from a visitor's browser — but with the `bunny()`
provider the _build itself_ could not run unless fonts.bunny.net answered. On
2026-08-24 it twice did not, in the same afternoon: a connection timeout on a
CI runner and a blocked host in a sandbox. Each failed `npm run build`
outright, which in CI is a red check and on the server is a failed deploy. A
typeface that has not changed since 2022 is not worth a runtime dependency on
somebody else's uptime.

`@fontsource/*` npm packages were the obvious alternative and were tried
first. They are rejected for a specific reason worth writing down so nobody
tries again: each package ships `.woff2` **and** `.woff` for every face, the
plugin emits one `@font-face` rule per file, and two rules with identical
family, weight, style and unicode-range resolve to the _last_ one — the
`.woff`. That is 149 KB of fonts where 118 KB would do, on a product whose
first surface is a phone. Naming the files explicitly is what stops the
build making that choice for us.

## What is here

| File                                     | Family          | Weight |
| ---------------------------------------- | --------------- | ------ |
| `instrument-sans-latin-400-normal.woff2` | Instrument Sans | 400    |
| `instrument-sans-latin-500-normal.woff2` | Instrument Sans | 500    |
| `instrument-sans-latin-600-normal.woff2` | Instrument Sans | 600    |
| `fraunces-latin-500-normal.woff2`        | Fraunces        | 500    |
| `inter-latin-400-normal.woff2`           | Inter           | 400    |
| `inter-latin-600-normal.woff2`           | Inter           | 600    |

Latin subset only, which is what the site was already serving — the five UI
locales (en, de, nl, fr, es) need nothing else. A language that does needs its
own subset file added here, not a switch flipped somewhere.

The filenames are Fontsource's own, kept unchanged so the provenance of a file
is readable from the file. `vite.config.ts` names each one with its weight
explicitly rather than parsing it back out of the name.

## Where they came from, and how to replace one

Extracted from the Fontsource packages (`@fontsource/instrument-sans`,
`@fontsource/fraunces`, `@fontsource/inter`, all v5.3.0), which repackage the
upstream Google Fonts releases. To update a face, install the package, copy
the matching `files/<family>-latin-<weight>-normal.woff2`, and copy its
`LICENSE` alongside — then remove the package again. Nothing at build or run
time depends on those packages being installed.

## Licence

All three are licensed under the **SIL Open Font License 1.1**, whose terms
travel with the files: `LICENSE-instrument-sans.txt`, `LICENSE-fraunces.txt`,
`LICENSE-inter.txt`. The OFL permits bundling and redistribution as part of a
larger work, which is what serving them from `/build/assets` is; it requires
the notice and licence to accompany the software, which is why those three
files sit next to the fonts and must not be tidied away.

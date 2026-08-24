import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            // Served from resources/fonts/, not fetched from a font CDN at
            // build time. The plugin self-hosts either way — it emits the
            // files into the bundle, so nothing ever reached a CDN from a
            // visitor's browser — but with `bunny()` the *build* could not run
            // unless fonts.bunny.net answered, and on 2026-08-24 it twice did
            // not: a timeout on a CI runner and a blocked host in a sandbox,
            // each of which failed `npm run build` outright and so would have
            // failed a deploy.
            //
            // Every face is named with its weight rather than left to be
            // inferred, and only .woff2 is listed. Both are deliberate — see
            // resources/fonts/README.md for the 31 KB the alternative cost.
            //
            // Same families and weights as before, and every plugin default
            // is left alone (display: swap, preload on), so this changes where
            // the bytes come from and nothing else.
            //
            // `optimizedFallbacks` is one of those defaults and is now doing
            // something: the optional `fontaine` package is installed, so the
            // build also emits an "<family> fallback" face — Arial with
            // ascent, descent and size-adjust overridden to the real face's
            // metrics — which is what stops the page reflowing when a webfont
            // finishes loading. Generating it is only half of it: the name has
            // to appear in the stack the page reads, which is resources/css/
            // app.css and kaia-home.css, not the --font-* variables the plugin
            // publishes and nothing here consumes.
            fonts: [
                local('Instrument Sans', {
                    variants: [
                        {
                            src: 'resources/fonts/instrument-sans-latin-400-normal.woff2',
                            weight: 400,
                            style: 'normal',
                        },
                        {
                            src: 'resources/fonts/instrument-sans-latin-500-normal.woff2',
                            weight: 500,
                            style: 'normal',
                        },
                        {
                            src: 'resources/fonts/instrument-sans-latin-600-normal.woff2',
                            weight: 600,
                            style: 'normal',
                        },
                    ],
                }),
                local('Fraunces', {
                    variants: [
                        {
                            src: 'resources/fonts/fraunces-latin-500-normal.woff2',
                            weight: 500,
                            style: 'normal',
                        },
                    ],
                }),
                local('Inter', {
                    variants: [
                        {
                            src: 'resources/fonts/inter-latin-400-normal.woff2',
                            weight: 400,
                            style: 'normal',
                        },
                        {
                            src: 'resources/fonts/inter-latin-600-normal.woff2',
                            weight: 600,
                            style: 'normal',
                        },
                    ],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});

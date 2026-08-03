import type { CapacitorConfig } from '@capacitor/cli';

// NamibWay ships as a server-rendered Laravel/Inertia app, not a static SPA bundle —
// Inertia navigation depends on hitting the Laravel backend for every page, so there's
// nothing to usefully bundle from `public/build` for offline use. Instead of a static
// server.url redirect, `webDir` (ios-shell/) is a small bootstrap page: it probes for a
// live connection to namibway.com and only then navigates the WebView there, showing a
// branded offline/retry screen if the probe fails. See ios-shell/index.html.
const config: CapacitorConfig = {
    appId: 'com.namibway.app',
    appName: 'NamibWay',
    webDir: 'ios-shell',
    server: {
        // Without this, Capacitor treats navigation to namibway.com as external (it
        // only matches the app's own id/origin by default) and hands it off to the
        // system browser instead of loading it in the WebView.
        allowNavigation: ['namibway.com', 'www.namibway.com'],
    },
};

export default config;

import type { CapacitorConfig } from '@capacitor/cli';

// NamibWay ships as a server-rendered Laravel/Inertia app, not a static SPA bundle —
// Inertia navigation depends on hitting the Laravel backend for every page, so the
// native shell points its WebView at the live site (server.url) instead of trying to
// bundle `public/build` output as an offline app. `webDir` below only supplies the
// brief branded splash shown before the WebView swaps over to namibway.com.
const config: CapacitorConfig = {
    appId: 'com.namibway.app',
    appName: 'NamibWay',
    webDir: 'ios-shell',
    server: {
        url: 'https://namibway.com',
        cleartext: false,
    },
};

export default config;

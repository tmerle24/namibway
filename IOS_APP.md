# iOS app (Capacitor wrapper)

NamibWay ships as a server-rendered Laravel/Inertia app, not a static SPA — Inertia
navigation depends on hitting the Laravel backend for every page. So instead of bundling
`public/build` as an offline app, the native shell (Capacitor) points its WebView straight
at the live site:

- `capacitor.config.ts` — `server.url` is hardcoded to `https://namibway.com`.
- `ios-shell/` — a one-file branded splash (`#3b2418` background + compass icon) shown for
  the brief moment before the WebView swaps to the live URL. This is Capacitor's `webDir`,
  not the app's real UI.
- `ios/` — the generated Xcode project (`App.xcodeproj`). Uses Swift Package Manager for
  Capacitor's runtime, not CocoaPods, so there's no `pod install` step.

Everything above was generated/edited in a Linux dev environment and can't be built or
signed here. **The steps below need Xcode**, i.e. the virtual Mac.

## Steps on the Mac

1. `git pull` this branch, then `npm install`.
2. `npx cap sync ios` — regenerates `ios/App/App/public` and `capacitor.config.json` from
   `capacitor.config.ts` (both are gitignored, so this must be run fresh after every pull).
3. `open ios/App/App.xcodeproj`.
4. In **Signing & Capabilities**, set the Team to the Apple Developer account once its
   enrollment review has gone through, and confirm the bundle identifier
   `com.namibway.app` — register it as an App ID in the Apple Developer portal and create
   the corresponding app record in App Store Connect before archiving.
5. Run on a simulator/device first and confirm it loads `namibway.com` correctly (login,
   Kaia chat, itinerary — the usual smoke test).
6. **Product → Archive**, then **Distribute App** via the Organizer to upload a TestFlight
   build.
7. Fill in the App Store Connect listing (screenshots, description, support URL, privacy
   policy URL, age rating) and submit for review.

## Before actually submitting: read this

A plain WebView pointed at a website is a common App Store rejection under **Guideline
4.2 (Minimum Functionality)** — Apple explicitly rejects apps that "simply wrap web
content" with no native value-add. Two things already in the repo help (the PWA manifest
and `sw.js`/`offline.html` show some app-like intent), but a bare wrapper with zero native
integrations is still a real rejection risk. Worth adding at least one genuine native
capability before submitting for real — push notifications for booking confirmations
(fits the request-governance flow in `CLAUDE.md`) is the most natural fit and reuses
infrastructure the backend needs anyway.

Given the core booking flow is still actively changing (see `CLAUDE.md`), treat this
Capacitor setup as infrastructure prep to validate on the Mac before it's cancelled —
not as a signal to submit to the App Store this week.

# iOS / Android apps (Capacitor wrapper)

NamibWay ships as a server-rendered Laravel/Inertia app, not a static SPA — Inertia
navigation depends on hitting the Laravel backend for every page. So instead of bundling
`public/build` as an offline app, the native shell (Capacitor) points its WebView straight
at the live site:

- `capacitor.config.ts` — `server.url` is hardcoded to `https://namibway.com`. Shared by
  both platforms.
- `ios-shell/` — a one-file branded splash (`#3b2418` background + compass icon) shown for
  the brief moment before the WebView swaps to the live URL. This is Capacitor's `webDir`,
  not the app's real UI.
- `ios/` — the generated Xcode project (`App.xcodeproj`). Uses Swift Package Manager for
  Capacitor's runtime, not CocoaPods, so there's no `pod install` step.
- `android/` — the generated Android Studio/Gradle project.

Everything above was generated/edited in a Linux dev environment and can't be built or
signed here.

## App icons

Both platforms' launcher icons are derived from the existing PWA icon
(`public/images/pwa/icon-512.png` — the tan compass mark on the `#3b2418` brown
background), so the app icon matches the PWA/web branding exactly:

- **iOS**: `ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png` — a single
  1024x1024, alpha-flattened PNG (Apple rejects an App Store icon with transparency).
- **Android (adaptive icon)**: `android/app/src/main/res/mipmap-*/ic_launcher_foreground.png`
  is the compass mark keyed to transparent and scaled to Android's safe zone (66% of the
  icon canvas, so it isn't clipped by circle/squircle/rounded-square launcher masks); the
  background layer is a solid color (`values/ic_launcher_background.xml`, set to
  `#3B2418`) rather than a raster image, so it's crisp at any mask shape. Legacy (pre-Android
  8) `ic_launcher.png`/`ic_launcher_round.png` fall back to the full flat square icon.
- **Play Store listing icon**: `android/play-store-icon-512.png` (512x512, no alpha) — for
  the Play Console listing upload, not bundled into the app itself.

If the compass mark ever changes, regenerate all of these from the new
`public/images/pwa/icon-512.png` rather than hand-editing — the Android foreground was
produced by chroma-keying out the brown background and re-centering the mark at a smaller
scale, which isn't a simple resize.

## Steps on the Mac (iOS)

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

## Steps for Android (no Mac needed)

Unlike iOS, this doesn't need the virtual Mac — Android Studio/Gradle runs fine on any OS.

1. `git pull`, `npm install`, `npx cap sync android`.
2. Open `android/` in Android Studio (or `./gradlew assembleDebug`/`bundleRelease` from the
   CLI once the Android SDK is installed).
3. Create the app in Google Play Console, register a signing key (Play App Signing is the
   simplest option), and confirm the applicationId `com.namibway.app` matches.
4. Build a signed `.aab` and upload it to a Play Console testing track.
5. Fill in the Play Store listing (screenshots, feature graphic, description, privacy
   policy URL, content rating questionnaire) and submit for review.

## Before actually submitting: read this

A plain WebView pointed at a website is a common rejection reason on **both stores** —
Apple's Guideline 4.2 (Minimum Functionality) explicitly targets apps that "simply wrap web
content" with no native value-add, and Google Play's spam/minimum-functionality policy is
similar. Two things already in the repo help (the PWA manifest and `sw.js`/`offline.html`
show some app-like intent), but a bare wrapper with zero native integrations is still a
real rejection risk on either store. Worth adding at least one genuine native capability
before submitting for real — push notifications for booking confirmations (fits the
request-governance flow in `CLAUDE.md`) is the most natural fit and reuses infrastructure
the backend needs anyway.

Given the core booking flow is still actively changing (see `CLAUDE.md`), treat this
Capacitor setup as infrastructure prep to validate before it's cancelled — not as a signal
to submit to either app store this week.

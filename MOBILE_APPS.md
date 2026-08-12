# iOS / Android apps (Capacitor wrapper)

NamibWay ships as a server-rendered Laravel/Inertia app, not a static SPA — Inertia
navigation depends on hitting the Laravel backend for every page, so there's nothing
meaningful to bundle from `public/build` for offline use. Instead the native shell loads a
small bootstrap page first:

- `capacitor.config.ts` — no `server.url`; `webDir` (`ios-shell/`) is the app's real entry
  point. Shared by both platforms. `appendUserAgent: 'NamibWayApp'` is how the server
  recognises a shell request at all: the WebView loads namibway.com over ordinary HTTPS,
  so without the marker an app request is indistinguishable from Safari. Read by
  `App\Support\NativeApp`, mirrored client-side in `resources/js/composables/useIsApp.ts`
  because a shell built before the marker existed still reports a plain browser UA.
  **Changing this string breaks both**, and only a rebuilt shell carries it.

  The first thing it decides: social login (Google/Facebook/Apple) is not offered inside
  the shells. An OAuth redirect leaves the WebView for the system browser, so the traveller
  would finish signing in outside the app they started in. The PWA is unaffected — it runs
  in the browser's own engine and comes back to the same window.
- `ios-shell/index.html` — on launch, probes for a live connection to `namibway.com`
  (`fetch(..., {mode: 'no-cors'})` with a 6s timeout) and only then navigates the WebView
  there via `location.replace`. If the probe fails, it shows a branded "No internet
  connection" screen with a Retry button, and auto-retries on the browser's `online`
  event. This is the one place both platforms share, so the offline/retry logic isn't
  duplicated in native code.
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

## Splash screens

The native launch screen (shown instantly on cold start, before the WebView/bootstrap page
even initializes) is also branded to match:

- **iOS**: `ios/App/App/Assets.xcassets/Splash.imageset` — a 2732x2732 brown/compass image
  used for all three scale variants.
- **Android**: `android/app/src/main/res/drawable{,-land-*,-port-*}/splash.png` — one PNG
  per density/orientation, referenced by `AppTheme.NoActionBarLaunch` in `values/styles.xml`.
  Capacitor's default template ships this theme without an app-level `values/colors.xml`
  at all (`@color/colorPrimary` etc. in `styles.xml` would fail to resolve at build time) —
  added one, using the same brown/tan palette, fixing that and giving the status bar a
  matching color.

Both were regenerated the same way as the app icons: the compass mark from
`public/images/pwa/icon-512.png`, centered on the brand brown.

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

## Voice input (Kaia)

Kaia's chat (`resources/js/components/home/HeroChat.vue`) has a microphone button for
dictating messages instead of typing. In the browser/PWA this uses the Web Speech API
(`window.SpeechRecognition`/`webkitSpeechRecognition`) — but WKWebView doesn't implement
that API at all, so it never worked on iOS, only on Android's Chromium-based WebView.

Inside the wrapped app, voice input now goes through
`@capgo/capacitor-speech-recognition` instead (Apple's Speech framework / Android's
`SpeechRecognizer`), gated on `Capacitor.isNativePlatform()`. This plugin was chosen
specifically because it ships both `Package.swift` and a podspec — our iOS project uses
Swift Package Manager (no Podfile), and most Capacitor plugins (including
`@capacitor-community/speech-recognition`, the more commonly-referenced package) only
ship a podspec, which `cap sync` silently drops when the project is SPM-only (no error,
the plugin just isn't linked and every call fails at runtime as "not implemented"). If a
plugin needs to be swapped later, check for a `Package.swift` in its published package
first, or the native call will silently do nothing on iOS.

Required native permission strings are already in place:

- **iOS**: `NSMicrophoneUsageDescription` and `NSSpeechRecognitionUsageDescription` in
  `ios/App/App/Info.plist`.
- **Android**: `RECORD_AUDIO` — merged in automatically from the plugin's own manifest,
  nothing to add to `AndroidManifest.xml`.

## Before actually submitting: read this

A plain WebView pointed at a website is a common rejection reason on **both stores** —
Apple's Guideline 4.2 (Minimum Functionality) explicitly targets apps that "simply wrap web
content" with no native value-add, and Google Play's spam/minimum-functionality policy is
similar. The native voice input above is a genuine example of this; push notifications for
booking confirmations (fits the request-governance flow in `CLAUDE.md`) would be another
good one to add before submitting for real.

Given the core booking flow is still actively changing (see `CLAUDE.md`), treat this
Capacitor setup as infrastructure prep to validate before it's cancelled — not as a signal
to submit to either app store this week.

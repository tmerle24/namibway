import { computed } from 'vue';

// True when running inside a native app WebView wrapper (Capacitor is the
// default choice for wrapping this Inertia/Vue frontend as an iOS/Android
// app — no native shell exists yet, so this is forward-looking scaffolding).
// A future Capacitor build exposes `window.Capacitor.isNativePlatform()`.
export function useIsApp() {
    return computed(
        () =>
            typeof window !== 'undefined' &&
            Boolean(
                (
                    window as {
                        Capacitor?: { isNativePlatform?: () => boolean };
                    }
                ).Capacitor?.isNativePlatform?.(),
            ),
    );
}

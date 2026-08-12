import { computed } from 'vue';

// The marker Capacitor appends to the user agent (capacitor.config.ts). Kept in
// step with App\Support\NativeApp::USER_AGENT_MARKER, which is how the server
// answers the same question.
const USER_AGENT_MARKER = 'NamibWayApp';

// True when running inside the iOS/Android shell rather than a browser or the
// installed PWA. Two signals, because neither is sufficient on its own: the
// Capacitor bridge is only present if it survives the shell's navigation to
// namibway.com, and the user-agent marker only exists in a shell built after
// it was configured. A PWA is deliberately *not* an app here — it runs in the
// browser's own engine, so an OAuth redirect comes back to the same window.
export function useIsApp() {
    return computed(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        const bridge = (
            window as {
                Capacitor?: { isNativePlatform?: () => boolean };
            }
        ).Capacitor?.isNativePlatform?.();

        return (
            Boolean(bridge) || navigator.userAgent.includes(USER_AGENT_MARKER)
        );
    });
}

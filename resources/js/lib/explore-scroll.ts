// Round-trips the scroll position through a "back to overview" visit to a
// listing and back, so returning to the homepage lands where the user left
// off instead of at the top of the page. Session-scoped: a fresh tab/visit
// never carries a stale position.
const EXPLORE_SCROLL_KEY = 'namibway_explore_scroll';

export function saveExploreScroll(): void {
    try {
        sessionStorage.setItem(EXPLORE_SCROLL_KEY, String(window.scrollY));
    } catch {
        // ignore storage errors (private browsing, quota, etc.)
    }
}

export function hasSavedExploreScroll(): boolean {
    try {
        return sessionStorage.getItem(EXPLORE_SCROLL_KEY) !== null;
    } catch {
        return false;
    }
}

export function consumeExploreScroll(): number | null {
    try {
        const raw = sessionStorage.getItem(EXPLORE_SCROLL_KEY);
        sessionStorage.removeItem(EXPLORE_SCROLL_KEY);

        return raw !== null ? Number(raw) : null;
    } catch {
        return null;
    }
}

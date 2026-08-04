// Mobile browser chrome (Safari's floating bottom bar, Chrome's toolbar,
// standalone-PWA chrome) isn't accounted for consistently by CSS viewport
// units across browser versions — see the comment on --app-vvh's only
// consumer in kaia-home.css for the specific bugs this sidesteps.
// window.visualViewport.height is the one number that's always the
// *actually visible* height, in every engine that supports it (all current
// mobile Safari/Chrome, including installed-PWA mode), so publish it as a
// CSS var and let layout react to it live.
export function initializeViewportHeightVar() {
    function setViewportHeightVar() {
        const height = window.visualViewport?.height ?? window.innerHeight;
        document.documentElement.style.setProperty('--app-vvh', `${height}px`);
    }

    setViewportHeightVar();
    window.visualViewport?.addEventListener('resize', setViewportHeightVar);
    window.addEventListener('resize', setViewportHeightVar);
}

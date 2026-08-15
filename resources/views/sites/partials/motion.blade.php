{{--
  All of the page's JavaScript.

  Two behaviours, both enhancements: a class on the navigation once the page has
  scrolled, and a staggered reveal as sections come into view. No library, no
  framework, nothing fetched. The page is complete without any of it — the
  reveal styles only apply under `.js`, which this file's own script added, so a
  browser with scripting off never sees a hidden element it cannot reveal.

  `prefers-reduced-motion` is honoured in CSS rather than here, so the observer
  still marks sections as seen and nothing depends on the animation having run.
--}}
<script>
    (function () {
        var nav = document.getElementById('nav');

        // On every page, not only the ones with a hero to scroll off. The class
        // used to mean "the bar is over the page rather than over a
        // photograph", which a page with no hero never needs — but it now also
        // brings in the enquiry button, and that is wanted wherever the opening
        // screen has gone. On a solid bar the colour rules are already what
        // this would set, so there is nothing to undo.
        if (nav) {
            var onScroll = function () {
                nav.classList.toggle('is-scrolled', window.scrollY > 40);
            };
            addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }

        // The burger. Both it and its panel arrive hidden, so this script is
        // what makes the control exist at all — a page with JavaScript off gets
        // no button that cannot do anything, and loses nothing, because the
        // sections it links to are simply further down the same page.
        var burger = document.getElementById('nav-burger');
        var panel = document.getElementById('nav-panel');

        if (burger && panel) {
            burger.hidden = false;

            var setOpen = function (open) {
                burger.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.hidden = !open;
                // The panel is a cream sheet under the bar, so the bar has to
                // stop being white-on-a-photograph at the same instant.
                if (nav) nav.classList.toggle('is-open', open);
            };

            burger.addEventListener('click', function () {
                setOpen(burger.getAttribute('aria-expanded') !== 'true');
            });

            // Every link closes it: the target is on this page, so leaving the
            // panel open would cover what the visitor just asked to see.
            panel.addEventListener('click', function (event) {
                if (event.target.tagName === 'A') setOpen(false);
            });

            addEventListener('keydown', function (event) {
                if (event.key === 'Escape') setOpen(false);
            });
        }

        var targets = document.querySelectorAll('.reveal');

        if (!('IntersectionObserver' in window)) {
            for (var i = 0; i < targets.length; i++) targets[i].classList.add('in');
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry, index) {
                if (!entry.isIntersecting) return;
                // Staggered, but only within one batch — a section arriving on
                // its own should not wait for a delay it cannot see.
                entry.target.style.transitionDelay = (index * 70) + 'ms';
                entry.target.classList.add('in');
                observer.unobserve(entry.target);
            });
        });
        // No negative rootMargin, deliberately. Holding the reveal back until
        // an element is some way into the viewport looks slightly better and
        // has a failure mode that is not worth it: on a short page that cannot
        // scroll, an element sitting in that held-back strip never reveals and
        // the content is invisible with no way for the visitor to fix it.

        targets.forEach(function (target) { observer.observe(target); });

        // Lightbox: open zoomable images in a full-screen overlay.
        // Enhancement only — if the overlay markup is absent the loop below finds
        // nothing and exits without error, so a server-side error in the partial
        // cannot break the rest of the page.
        (function () {
            var lb   = document.getElementById('lb');
            var img  = document.getElementById('lb-img');
            var prev = document.getElementById('lb-prev');
            var next = document.getElementById('lb-next');

            if (!lb || !img) return;

            // All images that opted in via data-lb, in document order.
            var srcs = [];
            var alts = [];

            document.querySelectorAll('[data-lb]').forEach(function (el) {
                var idx = srcs.length;
                srcs.push(el.getAttribute('data-lb'));
                alts.push(el.alt || '');
                // The clickable area is the figure wrapping the img.
                var trigger = el.closest('figure') || el;
                trigger.addEventListener('click', function () { open(idx); });
            });

            var current = 0;

            function open(idx) {
                current = idx;
                img.src = srcs[current];
                img.alt = alts[current];
                lb.classList.add('is-open');
                document.body.style.overflow = 'hidden';
                if (prev) prev.hidden = srcs.length <= 1;
                if (next) next.hidden = srcs.length <= 1;
                if (prev) prev.disabled = current === 0;
                if (next) next.disabled = current === srcs.length - 1;
            }

            function close() {
                lb.classList.remove('is-open');
                document.body.style.overflow = '';
                // Clear src so the browser releases memory; the image reloads on
                // the next open, which is cheap because it comes from browser cache.
                img.src = '';
            }

            var closeBtn = document.getElementById('lb-close');
            if (closeBtn) closeBtn.addEventListener('click', close);

            // Click on the dark backdrop (not the image itself) closes.
            lb.addEventListener('click', function (e) { if (e.target === lb) close(); });

            if (prev) prev.addEventListener('click', function () { if (current > 0) open(current - 1); });
            if (next) next.addEventListener('click', function () { if (current < srcs.length - 1) open(current + 1); });

            addEventListener('keydown', function (e) {
                if (!lb.classList.contains('is-open')) return;
                if (e.key === 'Escape') { close(); return; }
                if (e.key === 'ArrowLeft'  && current > 0)               open(current - 1);
                if (e.key === 'ArrowRight' && current < srcs.length - 1) open(current + 1);
            });
        })();
    })();
</script>

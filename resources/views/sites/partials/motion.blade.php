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

        if (nav && !nav.classList.contains('nav--solid')) {
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
    })();
</script>

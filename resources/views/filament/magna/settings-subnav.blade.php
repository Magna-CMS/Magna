{{--
    Settings sub-nav: intercept clicks on the section links so they
    smooth-scroll (Filament's SPA navigation otherwise swallows the anchor),
    and drive a scroll-spy that moves the active highlight in the sidebar as
    the user scrolls through sections. Lives in a persistent render hook and
    re-runs on every Livewire navigation.
--}}
<script>
    (function () {
        // Filament overrides custom section ids, so we map sidebar
        // links to sections by their heading text instead.
        var SLUGS = {
            general: 'General', localization: 'Localization', content: 'Content',
            media: 'Media', email: 'Email', storage: 'Storage',
            urls: 'URLs & Frontend', security: 'Security', performance: 'Performance'
        };
        var HEADINGS = {};
        Object.keys(SLUGS).forEach(function (s) { HEADINGS[SLUGS[s].toLowerCase()] = s; });
        var observer = null;

        function norm(t) { return (t || '').replace(/\s+/g, ' ').trim(); }

        function sectionForSlug(slug) {
            var heading = SLUGS[slug];
            if (! heading) return null;
            var secs = document.querySelectorAll('.fi-section');
            for (var i = 0; i < secs.length; i++) {
                var h = secs[i].querySelector('.fi-section-header-heading');
                if (h && norm(h.textContent) === heading) return secs[i];
            }
            return null;
        }

        function scrollParent(el) {
            var p = el.parentElement;
            while (p && p !== document.body) {
                var oy = getComputedStyle(p).overflowY;
                if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) return p;
                p = p.parentElement;
            }
            return null;
        }

        // scrollIntoView({behavior:'smooth'}) proved unreliable
        // here, so compute the target position and scroll the
        // real scroller (window or an overflow container) directly.
        function scrollToSection(el) {
            var offset = 90; // clear the sticky topbar
            var sp = scrollParent(el);
            if (sp) {
                sp.scrollTo({ top: el.offsetTop - offset, behavior: 'smooth' });
            } else {
                window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - offset, behavior: 'smooth' });
            }
        }

        // Livewire's wire:navigate is a document capture-phase
        // handler that fires before ours and doesn't stop the
        // event, so it would navigate even when we scroll. The
        // only reliable defense is to keep the attribute off the
        // section links entirely — and re-strip it the instant
        // Livewire re-adds it while morphing the sidebar (which is
        // exactly what happens after an "All Settings" click).
        function stripNavigate() {
            document.querySelectorAll('a[href*="#settings-"]').forEach(function (l) {
                l.removeAttribute('wire:navigate');
                l.removeAttribute('wire:navigate.hover');
            });
        }
        stripNavigate();
        // Observe the whole body so the guard survives Livewire
        // replacing the sidebar element on navigation.
        new MutationObserver(stripNavigate).observe(document.body, {
            childList: true, subtree: true, attributes: true,
            attributeFilter: ['wire:navigate', 'wire:navigate.hover']
        });

        // Delegated capture-phase click interceptor for the
        // in-page smooth scroll (and to cancel the native hash
        // jump). With wire:navigate stripped above, no SPA
        // navigation fires, so there is no page reload.
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a[href*="#settings-"]') : null;
            if (! link) return;
            var m = (link.getAttribute('href') || '').match(/#settings-([\w-]+)/);
            if (! m) return;
            var el = sectionForSlug(m[1]);
            if (! el) return; // section not on this page — allow normal navigation
            e.preventDefault();
            e.stopImmediatePropagation();
            scrollToSection(el);
            history.replaceState(null, '', '#settings-' + m[1]);
        }, true);

        function setupSpy() {
            if (observer) { observer.disconnect(); observer = null; }

            var sections = Array.prototype.slice.call(document.querySelectorAll('.fi-section')).filter(function (s) {
                var h = s.querySelector('.fi-section-header-heading');
                return h && HEADINGS[norm(h.textContent).toLowerCase()];
            });
            if (! sections.length) return;

            var linkBySlug = {};
            Array.prototype.slice.call(document.querySelectorAll('a[href*="#settings-"]')).forEach(function (link) {
                var m = (link.getAttribute('href') || '').match(/#settings-([\w-]+)/);
                if (m) linkBySlug[m[1]] = link;
            });

            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (! entry.isIntersecting) return;
                    var h = entry.target.querySelector('.fi-section-header-heading');
                    var slug = h ? HEADINGS[norm(h.textContent).toLowerCase()] : null;
                    if (! slug) return;
                    Object.keys(linkBySlug).forEach(function (k) { linkBySlug[k].classList.remove('magna-nav-active'); });
                    if (linkBySlug[slug]) linkBySlug[slug].classList.add('magna-nav-active');
                });
            }, { rootMargin: '-15% 0px -75% 0px', threshold: 0 });
            sections.forEach(function (s) { observer.observe(s); });

            if (window.location.hash.indexOf('#settings-') === 0) {
                var el = sectionForSlug(window.location.hash.replace('#settings-', ''));
                if (el) requestAnimationFrame(function () { scrollToSection(el); });
            }
        }

        document.addEventListener('DOMContentLoaded', setupSpy);
        document.addEventListener('livewire:navigated', setupSpy);
    })();
</script>

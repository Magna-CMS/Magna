{{--
    Behaviour: scheme-toggle.

    Separate from the block's view because a theme REPLACES that view, and
    everything below is what makes the control exist at all: it un-hides the
    button, binds the click, flips `data-theme` on the root, and remembers
    the choice. A theme that overrode the view to restyle the button used to
    lose all four — and the §C8 audit then refused it a <script> of its own,
    so the only honest repair was to reimplement this in the layout. The
    result was worse than a styling regression: a control that never
    appeared, with nothing saying anything had been dropped.

    So markup is the theme's and behaviour is the block's. This file is
    resolved on the `magna::` namespace only — never through the theme
    chain — and included by the renderer beside whichever view won.

    What is here is only what the behaviour REQUIRES. The button's own
    chrome stays with the markup in the block view, where a theme replacing
    the button replaces its look too. The icon swap is here because the
    script does not perform it: the script writes `data-theme` and the rules
    below are what turn that into a visible change of icon.

    Every rule is wrapped in :where(), so it carries ZERO specificity and
    any rule a theme writes outbids it. That is the point: this ships
    unconditionally now, and a theme that disagrees about how the two icons
    swap must still win, without having to escalate selectors to do it.

    @once because a page may carry the switch more than once (a header and a
    footer, say) and one copy of this wires every button it finds.
--}}
@once
    <style>
        /* One icon at a time: which one depends on the scheme in force,
           which is the same three-state question the tokens answer. */
        :where(.magna-scheme-toggle__dark) { display: none; }
        @media (prefers-color-scheme: dark) {
            :where(:root:not([data-theme="light"]) .magna-scheme-toggle__light) { display: none; }
            :where(:root:not([data-theme="light"]) .magna-scheme-toggle__dark) { display: inline-flex; }
        }
        :where(:root[data-theme="dark"] .magna-scheme-toggle__light) { display: none; }
        :where(:root[data-theme="dark"] .magna-scheme-toggle__dark) { display: inline-flex; }
        :where(:root[data-theme="light"] .magna-scheme-toggle__light) { display: inline-flex; }
        :where(:root[data-theme="light"] .magna-scheme-toggle__dark) { display: none; }
    </style>
    <script>
        (function () {
            var KEY = 'magna-theme'
            var root = document.documentElement

            /* Applied before anything is drawn where possible, so a visitor
               who chose dark does not watch the light palette flash first. */
            try {
                var stored = localStorage.getItem(KEY)
                if (stored === 'light' || stored === 'dark') {
                    root.setAttribute('data-theme', stored)
                }
            } catch (error) {
                /* Private mode, or storage disabled. The system preference
                   still applies; only remembering a choice is lost. */
            }

            function current() {
                var explicit = root.getAttribute('data-theme')
                if (explicit === 'light' || explicit === 'dark') {
                    return explicit
                }

                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light'
            }

            /* Per-state labels are OPT-IN, and they are the ALTERNATIVE to
               aria-pressed rather than an addition to it.

               A toggle button announces its state one of two ways: a stable
               name plus aria-pressed, or a name that describes the action it
               will perform next. Both are correct; doing both can announce
               twice on some screen readers. The default here is the first,
               which is also what the shipped markup declares.

               So a view that declares BOTH data-magna-label-to-light and
               data-magna-label-to-dark has chosen the second pattern, and
               should drop aria-pressed from its own markup — this script only
               writes the attribute, it cannot know a theme wanted it gone.
               Declaring one attribute alone changes nothing, so a half-done
               override cannot strand a button mid-rename.

               The block's single configured label ("Switch between light and
               dark") is the author's and possibly localised, which is why
               nothing is renamed unless a view asks: a shared script must not
               overwrite a translated label with English. */
            function relabel(button, state) {
                var toLight = button.getAttribute('data-magna-label-to-light')
                var toDark = button.getAttribute('data-magna-label-to-dark')
                if (toLight && toDark) {
                    button.setAttribute('aria-label', state === 'dark' ? toLight : toDark)
                }
            }

            function reflect(button, state) {
                button.setAttribute('aria-pressed', state === 'dark' ? 'true' : 'false')
                relabel(button, state)
            }

            function wire(button) {
                /* A fragment re-render in the builder can hand the same
                   button back a second time; binding twice would make a
                   click flip the scheme and flip it straight back. */
                if (button.dataset.magnaSchemeWired === '1') {
                    return
                }
                button.dataset.magnaSchemeWired = '1'

                button.hidden = false
                reflect(button, current())

                button.addEventListener('click', function () {
                    var next = current() === 'dark' ? 'light' : 'dark'
                    root.setAttribute('data-theme', next)
                    try {
                        localStorage.setItem(KEY, next)
                    } catch (error) {
                        /* Not remembered, but switched. */
                    }

                    document.querySelectorAll('[data-magna-scheme-toggle]').forEach(function (other) {
                        reflect(other, next)
                    })
                })
            }

            document.querySelectorAll('[data-magna-scheme-toggle]').forEach(wire)
        })()
    </script>
@endonce

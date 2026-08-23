{{-- Block: scheme-toggle --}}
@php
    /*
     * The visitor's own light/dark switch.
     *
     * The page already carries both readings of the palette in one
     * stylesheet, so this changes nothing about what was served — it only
     * stamps `data-theme` on the root, which the token rules already
     * answer to. That is what keeps the page cacheable: every visitor gets
     * identical bytes and the choice lives in their browser.
     *
     * Rendered as a real <button> so it is focusable and announceable, and
     * hidden until the script runs: a switch that cannot work without
     * JavaScript should not be offered to someone who has none.
     */
    $registry = app(\Magna\Blocks\Icons\IconRegistry::class);

    $sizes = ['sm' => 16, 'md' => 20, 'lg' => 26];
    $size = $block['data']['size'] ?? 'md';
    $pixels = $sizes[is_string($size) ? $size : 'md'] ?? $sizes['md'];

    $label = trim((string) ($block['data']['label'] ?? ''));
    $label = $label === '' ? 'Switch between light and dark' : $label;
@endphp
<div class="magna-block magna-block--scheme-toggle magna-scheme-toggle">
    <button type="button"
            class="magna-scheme-toggle__button"
            data-magna-scheme-toggle
            aria-label="{{ $label }}"
            aria-pressed="false"
            hidden>
        <span class="magna-scheme-toggle__light" aria-hidden="true">{!! $registry->svg('core:sparkles', null, null, $pixels) !!}</span>
        <span class="magna-scheme-toggle__dark" aria-hidden="true">{!! $registry->svg('core:globe', null, null, $pixels) !!}</span>
    </button>
</div>
@once
    <style>
        .magna-scheme-toggle__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.4rem;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: inherit;
            cursor: pointer;
        }
        /* One icon at a time: which one depends on the scheme in force,
           which is the same three-state question the tokens answer. */
        .magna-scheme-toggle__dark { display: none; }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) .magna-scheme-toggle__light { display: none; }
            :root:not([data-theme="light"]) .magna-scheme-toggle__dark { display: inline-flex; }
        }
        :root[data-theme="dark"] .magna-scheme-toggle__light { display: none; }
        :root[data-theme="dark"] .magna-scheme-toggle__dark { display: inline-flex; }
        :root[data-theme="light"] .magna-scheme-toggle__light { display: inline-flex; }
        :root[data-theme="light"] .magna-scheme-toggle__dark { display: none; }
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

            function wire(button) {
                button.hidden = false
                button.setAttribute('aria-pressed', current() === 'dark' ? 'true' : 'false')

                button.addEventListener('click', function () {
                    var next = current() === 'dark' ? 'light' : 'dark'
                    root.setAttribute('data-theme', next)
                    try {
                        localStorage.setItem(KEY, next)
                    } catch (error) {
                        /* Not remembered, but switched. */
                    }

                    document.querySelectorAll('[data-magna-scheme-toggle]').forEach(function (other) {
                        other.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false')
                    })
                })
            }

            document.querySelectorAll('[data-magna-scheme-toggle]').forEach(wire)
        })()
    </script>
@endonce

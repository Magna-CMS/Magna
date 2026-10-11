{{--
    Theme-styled light/dark switch: proves a theme may replace the switch's
    MARKUP and still get core's behaviour, which it is not allowed to ship
    for itself. Everything the behaviour binds to is kept — the
    `data-magna-scheme-toggle` hook, the `hidden` it lifts, `aria-pressed`,
    and the two spans whose visibility it swaps.
--}}
<button type="button"
        class="fixture-theme-toggle"
        data-magna-scheme-toggle
        aria-label="{{ $block['data']['label'] ?? 'Switch between light and dark' }}"
        aria-pressed="false"
        hidden>
    <span class="magna-scheme-toggle__light" aria-hidden="true">light</span>
    <span class="magna-scheme-toggle__dark" aria-hidden="true">dark</span>
</button>

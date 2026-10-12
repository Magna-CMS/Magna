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
     *
     * MARKUP AND ITS OWN CHROME. What makes the switch WORK — the script
     * and the icon swap it drives — lives in
     * `block-behaviour/scheme-toggle.blade.php`, which the renderer
     * includes alongside whichever view won, so a theme may replace
     * everything below without replacing the behaviour it is not allowed
     * to ship for itself. What the behaviour binds to is therefore a
     * contract this markup keeps, and an override must keep too: the
     * `data-magna-scheme-toggle` hook, the `hidden` it lifts, and the
     * `__light`/`__dark` spans it swaps. An override may ALSO declare
     * `data-magna-label-to-light` and `data-magna-label-to-dark`; with both
     * present the behaviour renames the button per state, and without them
     * the configured label here is never touched.
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
{{--
    The default button's own chrome — and nothing the behaviour depends on.

    Here rather than in block-behaviour/ on purpose: a theme that replaces
    this markup is replacing the button, and the look of a button it no
    longer draws should go with it. The icon swap and the script stay in the
    behaviour file, which no override can drop.
--}}
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
    </style>
@endonce

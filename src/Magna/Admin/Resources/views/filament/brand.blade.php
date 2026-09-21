@php
    // Filament renders this partial more than once per page (e.g. the topbar's
    // desktop-only logo slot AND the mobile sidebar drawer). SVG `url(#id)`
    // references resolve to the FIRST matching id in the document regardless
    // of which copy they're in — and when that first copy sits inside a
    // display:none ancestor (hidden at the current breakpoint), Chromium
    // won't paint anything referencing its gradient, even from a second,
    // visible copy with its own (but ID-collided) <linearGradient>. Suffixing
    // the ids per-render keeps every copy self-contained.
    $mgbId = str()->random(8);
@endphp
<div class="magna-brand">
    <svg class="magna-brand-icon" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="mgb-grad-{{ $mgbId }}" x1="0" y1="0" x2="34" y2="34">
                <stop stop-color="#6366f1"/>
                <stop offset="1" stop-color="#8b5cf6"/>
            </linearGradient>
            <linearGradient id="mgb-neon-{{ $mgbId }}" x1="0" y1="0" x2="34" y2="34">
                <stop offset="0%" stop-color="#00f2fe"/>
                <stop offset="50%" stop-color="#4facfe"/>
                <stop offset="100%" stop-color="#f355da"/>
            </linearGradient>
            <style>
                .mgb-line-{{ $mgbId }} {
                    stroke-dasharray: 28 56;
                    stroke-dashoffset: 84;
                    animation: mgb-chase 2s cubic-bezier(0.25, 1, 0.5, 1) infinite;
                }
                @keyframes mgb-chase {
                    0%   { stroke-dasharray: 15 69; stroke-dashoffset: 84; }
                    40%  { stroke-dasharray: 38 46; }
                    100% { stroke-dasharray: 15 69; stroke-dashoffset: 0; }
                }
            </style>
        </defs>
        <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mgb-grad-{{ $mgbId }})" stroke-width="2.4" fill="rgba(99,102,241,.06)"/>
        <path class="mgb-line-{{ $mgbId }}" d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mgb-neon-{{ $mgbId }})" stroke-width="2.6" stroke-linecap="round"/>
        <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mgb-grad-{{ $mgbId }})" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
    </svg>
    <span class="magna-brand-text">Magna</span>
</div>

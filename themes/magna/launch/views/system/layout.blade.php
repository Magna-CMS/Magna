<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}{{ $title !== '' ? ' — ' : '' }}{{ $siteName }}</title>
    @if($tokensCss !== '')
        <style>{!! $tokensCss !!}</style>
    @endif
    <style>
        /* Launch — clean, editorial, token-driven. No JS. */
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --_text: var(--color-text, #0f172a);
            --_muted: var(--color-muted, #64748b);
            --_surface: var(--color-surface, #ffffff);
            --_surface-alt: var(--color-surface-alt, #f1f5f9);
            --_primary: var(--color-primary, #2563eb);
            --_radius: var(--radius, 0.5rem);
            --_max: var(--max-width, 1152px);
        }
        /* No dark block of its own: every --_ variable above already reads
           from a design token, and a token carries both readings. A second
           copy here would be a palette to keep in step, and one this theme
           could not pin to a scheme the site had chosen. */
        html { font-size: var(--base-size, 16px); }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.65;
            color: var(--_text);
            background: var(--_surface);
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; height: auto; display: block; }
        a { color: var(--_primary); text-decoration: none; }
        a:hover { text-decoration: underline; }
        :focus-visible { outline: 2px solid var(--_primary); outline-offset: 2px; }

        .l-wrap { max-width: var(--_max); margin-inline: auto; padding-inline: clamp(1rem, 4vw, 2rem); }

        /* Header */
        .l-header { border-bottom: 1px solid color-mix(in srgb, var(--_muted) 25%, transparent); }
        .l-header__inner { display: flex; align-items: center; justify-content: space-between; gap: 2rem; padding-block: 1rem; }
        .l-brand { font-weight: 700; font-size: 1.125rem; color: var(--_text); letter-spacing: -0.01em; }
        .l-nav ul { display: flex; flex-wrap: wrap; gap: 1.5rem; list-style: none; margin: 0; padding: 0; }
        .l-nav a { color: var(--_muted); font-size: 0.95rem; }
        .l-nav a:hover { color: var(--_text); text-decoration: none; }

        /* Sections */
        .magna-section { padding-block: clamp(2.5rem, 7vw, 5rem); }
        .magna-section:nth-of-type(even) { background: var(--_surface-alt); }
        .magna-section__inner { max-width: var(--_max); margin-inline: auto; padding-inline: clamp(1rem, 4vw, 2rem); }
        .magna-columns { display: flex; flex-wrap: wrap; gap: clamp(1.5rem, 3vw, 2.5rem); }
        .magna-column { min-width: 0; }
        @media (max-width: 640px) { .magna-columns { flex-direction: column; } .magna-column { flex: 1 1 100% !important; } }

        /* Type scale (fluid) */
        h1, .t-display { font-size: clamp(2rem, 5vw, 3.25rem); line-height: 1.1; letter-spacing: -0.02em; margin: 0 0 1rem; }
        h2 { font-size: clamp(1.5rem, 3vw, 2.25rem); line-height: 1.2; letter-spacing: -0.015em; margin: 0 0 0.75rem; }
        h3 { font-size: clamp(1.2rem, 2vw, 1.5rem); margin: 0 0 0.5rem; }
        p { margin: 0 0 1rem; }

        /* Buttons */
        .btn {
            display: inline-block; padding: 0.65rem 1.4rem; border-radius: var(--_radius);
            font-weight: 600; font-size: 0.95rem; border: 1.5px solid transparent;
        }
        .btn--primary { background: var(--_primary); color: #fff; }
        .btn--primary:hover { filter: brightness(1.08); text-decoration: none; }
        .btn--outline { border-color: var(--_primary); color: var(--_primary); }
        .btn--outline:hover { background: var(--_primary); color: #fff; text-decoration: none; }

        /* Blocks */
        .b-hero { text-align: center; }
        .b-hero--split { text-align: left; }
        .b-hero__sub { color: var(--_muted); font-size: clamp(1.05rem, 1.5vw, 1.25rem); max-width: 42rem; margin-inline: auto; }
        .b-hero--split .b-hero__sub { margin-inline: 0; }
        .b-hero__ctas { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; margin-top: 1.75rem; }
        .b-hero--split .b-hero__ctas { justify-content: flex-start; }

        .b-prose { max-width: 65ch; }
        .b-prose :is(h2, h3) { margin-top: 1.5em; }
        .b-prose blockquote { margin: 1.5rem 0; padding-left: 1rem; border-left: 3px solid var(--_primary); color: var(--_muted); }
        .b-prose code { background: var(--_surface-alt); padding: 0.15em 0.4em; border-radius: 4px; font-size: 0.9em; }

        .b-cta { text-align: center; background: var(--_primary); color: #fff; border-radius: var(--_radius); padding: clamp(2rem, 5vw, 3.5rem); }
        .b-cta h2 { color: #fff; }
        .b-cta p { color: color-mix(in srgb, #ffffff 85%, transparent); max-width: 40rem; margin-inline: auto; }
        .b-cta .btn--primary { background: #fff; color: var(--_primary); }
        .b-cta .btn--outline { border-color: #fff; color: #fff; }

        .b-features { display: grid; gap: 1.75rem; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .b-feature h3 { display: flex; align-items: center; gap: 0.5rem; }
        .b-feature p { color: var(--_muted); font-size: 0.95rem; margin: 0; }

        .b-faq details { border-bottom: 1px solid color-mix(in srgb, var(--_muted) 25%, transparent); padding-block: 0.85rem; }
        .b-faq summary { font-weight: 600; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .b-faq summary::after { content: '+'; color: var(--_muted); font-size: 1.25rem; }
        .b-faq details[open] summary::after { content: '−'; }
        .b-faq .b-faq__a { color: var(--_muted); padding-top: 0.5rem; }

        .b-image figure { margin: 0; }
        .b-image img { border-radius: var(--_radius); }
        .b-image figcaption { color: var(--_muted); font-size: 0.875rem; margin-top: 0.5rem; text-align: center; }

        /* Footer */
        .l-footer { border-top: 1px solid color-mix(in srgb, var(--_muted) 25%, transparent); margin-top: clamp(2rem, 6vw, 4rem); }
        .l-footer__inner { padding-block: 2rem; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; color: var(--_muted); font-size: 0.9rem; }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>

<a class="sr-only" href="#main" style="position:absolute;left:-9999px">Skip to content</a>

@if(!empty($headerPartHtml))
    {{-- Site-designed header part replaces the theme's built-in chrome. --}}
    <header class="l-header l-header--custom">{!! $headerPartHtml !!}</header>
@else
    <header class="l-header">
        <div class="l-wrap l-header__inner">
            <a href="/" class="l-brand">{{ $siteName }}</a>
            @if(!empty($headerMenu))
                <nav class="l-nav" aria-label="Primary">
                    <ul>
                        @foreach($headerMenu as $item)
                            <li><a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url'] ?? null) }}" @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif>{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
    </header>
@endif

<main id="main">
    @if(!empty($mainHtml))
        {{-- Plugin frontend page: pre-rendered main slot. --}}
        <div class="l-wrap">{!! $mainHtml !!}</div>
    @else
        @include('magna-pages::partials.sections')
    @endif
</main>

@if(!empty($popupsHtml))
    {!! $popupsHtml !!}
@endif

@if(!empty($footerPartHtml))
    <footer class="l-footer l-footer--custom">{!! $footerPartHtml !!}</footer>
@else
    <footer class="l-footer">
        <div class="l-wrap l-footer__inner">
            <span>&copy; {{ now()->year }} {{ $siteName }}</span>
            <span>Built with Magna</span>
        </div>
    </footer>
@endif

</body>
</html>

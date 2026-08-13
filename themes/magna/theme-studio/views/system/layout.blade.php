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
        /* Studio — bold, dark-first, display serif. No JS. */
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --_text: var(--color-text, #e7e5e4);
            --_muted: var(--color-muted, #a8a29e);
            --_surface: var(--color-surface, #0c0a09);
            --_surface-alt: var(--color-surface-alt, #1c1917);
            --_accent: var(--color-primary, #f59e0b);
            --_radius: var(--radius, 0.125rem);
            --_max: var(--max-width, 1200px);
        }
        html { font-size: var(--base-size, 17px); }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.7;
            color: var(--_text);
            background: var(--_surface);
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; height: auto; display: block; }
        a { color: var(--_accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        :focus-visible { outline: 2px solid var(--_accent); outline-offset: 3px; }

        .s-wrap { max-width: var(--_max); margin-inline: auto; padding-inline: clamp(1.25rem, 5vw, 3rem); }

        /* Header — minimal rule line, uppercase nav */
        .s-header { border-bottom: 1px solid color-mix(in srgb, var(--_muted) 20%, transparent); }
        .s-header__inner { display: flex; align-items: baseline; justify-content: space-between; gap: 2rem; padding-block: 1.4rem; }
        .s-brand { font-family: Georgia, "Times New Roman", serif; font-weight: 700; font-size: 1.35rem; color: var(--_text); letter-spacing: 0.01em; }
        .s-nav ul { display: flex; flex-wrap: wrap; gap: 1.75rem; list-style: none; margin: 0; padding: 0; }
        .s-nav a { color: var(--_muted); font-size: 0.8rem; letter-spacing: 0.14em; text-transform: uppercase; }
        .s-nav a:hover { color: var(--_accent); text-decoration: none; }

        /* Sections */
        .magna-section { padding-block: clamp(3rem, 8vw, 6.5rem); }
        .magna-section:nth-of-type(even) { background: var(--_surface-alt); }
        .magna-section__inner { max-width: var(--_max); margin-inline: auto; padding-inline: clamp(1.25rem, 5vw, 3rem); }
        .magna-columns { display: flex; flex-wrap: wrap; gap: clamp(1.75rem, 4vw, 3rem); }
        .magna-column { min-width: 0; }
        @media (max-width: 640px) { .magna-columns { flex-direction: column; } .magna-column { flex: 1 1 100% !important; } }

        /* Type — display serif headings */
        h1, h2, h3 { font-family: Georgia, "Times New Roman", serif; font-weight: 700; }
        h1, .t-display { font-size: clamp(2.5rem, 7vw, 4.5rem); line-height: 1.05; letter-spacing: -0.015em; margin: 0 0 1.25rem; }
        h2 { font-size: clamp(1.75rem, 4vw, 2.75rem); line-height: 1.15; margin: 0 0 0.85rem; }
        h3 { font-size: clamp(1.25rem, 2.2vw, 1.6rem); margin: 0 0 0.5rem; }
        p { margin: 0 0 1rem; }

        /* Buttons — square, loud */
        .btn {
            display: inline-block; padding: 0.8rem 1.75rem; border-radius: var(--_radius);
            font-weight: 600; font-size: 0.85rem; letter-spacing: 0.1em; text-transform: uppercase;
            border: 1.5px solid transparent;
        }
        .btn--primary { background: var(--_accent); color: #0c0a09; }
        .btn--primary:hover { filter: brightness(1.1); text-decoration: none; }
        .btn--outline { border-color: var(--_accent); color: var(--_accent); }
        .btn--outline:hover { background: var(--_accent); color: #0c0a09; text-decoration: none; }

        /* Blocks */
        .b-hero h1 { max-width: 18ch; }
        .b-hero__rule { width: 4rem; height: 3px; background: var(--_accent); margin-bottom: 1.75rem; }
        .b-hero__sub { color: var(--_muted); font-size: clamp(1.1rem, 1.6vw, 1.35rem); max-width: 46rem; }
        .b-hero__ctas { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 2.25rem; }
        .b-hero--centered { text-align: center; }
        .b-hero--centered h1 { margin-inline: auto; }
        .b-hero--centered .b-hero__rule { margin-inline: auto; }
        .b-hero--centered .b-hero__sub { margin-inline: auto; }
        .b-hero--centered .b-hero__ctas { justify-content: center; }

        .b-prose { max-width: 62ch; }
        .b-prose :is(h2, h3) { margin-top: 1.6em; }
        .b-prose blockquote { margin: 1.75rem 0; padding-left: 1.25rem; border-left: 3px solid var(--_accent); color: var(--_muted); font-style: italic; }
        .b-prose code { background: var(--_surface-alt); padding: 0.15em 0.4em; border-radius: 4px; font-size: 0.9em; }

        .b-cta { border: 1px solid var(--_accent); border-radius: var(--_radius); padding: clamp(2.5rem, 6vw, 4rem); text-align: center; }
        .b-cta p { color: var(--_muted); max-width: 42rem; margin-inline: auto; }

        .b-features { display: grid; gap: 2.25rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); counter-reset: feature; }
        .b-feature { border-top: 1px solid color-mix(in srgb, var(--_muted) 25%, transparent); padding-top: 1.25rem; counter-increment: feature; }
        .b-feature h3::before { content: counter(feature, decimal-leading-zero); display: block; color: var(--_accent); font-family: ui-sans-serif, system-ui, sans-serif; font-size: 0.8rem; letter-spacing: 0.14em; margin-bottom: 0.5rem; }
        .b-feature p { color: var(--_muted); font-size: 0.95rem; margin: 0; }

        .b-faq details { border-bottom: 1px solid color-mix(in srgb, var(--_muted) 25%, transparent); padding-block: 1rem; }
        .b-faq summary { font-weight: 600; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .b-faq summary::after { content: '+'; color: var(--_accent); font-size: 1.35rem; }
        .b-faq details[open] summary::after { content: '−'; }
        .b-faq .b-faq__a { color: var(--_muted); padding-top: 0.5rem; }

        .b-image figure { margin: 0; }
        .b-image img { border-radius: var(--_radius); }
        .b-image figcaption { color: var(--_muted); font-size: 0.875rem; margin-top: 0.6rem; }

        /* Footer */
        .s-footer { border-top: 1px solid color-mix(in srgb, var(--_muted) 20%, transparent); margin-top: clamp(2.5rem, 7vw, 5rem); }
        .s-footer__inner { padding-block: 2.25rem; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; color: var(--_muted); font-size: 0.85rem; letter-spacing: 0.05em; }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>

<a class="sr-only" href="#main" style="position:absolute;left:-9999px">Skip to content</a>

@if(!empty($headerPartHtml))
    {{-- Site-designed header part replaces the theme's built-in chrome. --}}
    <header class="s-header s-header--custom">{!! $headerPartHtml !!}</header>
@else
    <header class="s-header">
        <div class="s-wrap s-header__inner">
            <a href="/" class="s-brand">{{ $siteName }}</a>
            @if(!empty($headerMenu))
                <nav class="s-nav" aria-label="Primary">
                    <ul>
                        @foreach($headerMenu as $item)
                            <li><a href="{{ $item['url'] }}" @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif>{{ $item['label'] }}</a></li>
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
        <div class="s-wrap">{!! $mainHtml !!}</div>
    @else
        @include('magna-pages::partials.sections')
    @endif
</main>

@if(!empty($popupsHtml))
    {!! $popupsHtml !!}
@endif

@if(!empty($footerPartHtml))
    <footer class="s-footer s-footer--custom">{!! $footerPartHtml !!}</footer>
@else
    <footer class="s-footer">
        <div class="s-wrap s-footer__inner">
            <span>&copy; {{ now()->year }} {{ $siteName }}</span>
            <span>Built with Magna</span>
        </div>
    </footer>
@endif

</body>
</html>

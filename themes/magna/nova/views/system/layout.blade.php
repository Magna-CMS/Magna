<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}{{ $title !== '' ? ' — ' : '' }}{{ $siteName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @if($tokensCss !== '')
        <style>{!! $tokensCss !!}</style>
    @endif
    <style>
        /*
            Nova — the Magna product-site design system.

            Everything below is written against the classes the block
            renderer emits (.magna-section, .magna-block--*, .magna-prose)
            and against a small vocabulary of section CSS classes an editor
            types into the builder's "CSS class" field (nova-why, nova-cards,
            nova-rows, …). There is no markup here that only a hand-written
            template could produce, which is what keeps every page on this
            site editable in the page builder.

            Colour comes from design tokens, so a token change in Appearance
            restyles the site; the --nova-* layer below is the theme's own
            naming on top of them, and it is what block-level style settings
            in the builder reference (background: var(--nova-card)).
        */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --nova-primary: var(--color-primary, #6366f1);
            --nova-primary-deep: var(--color-primary-deep, #4f52e0);
            --nova-primary-alt: var(--color-primary-alt, #8b5cf6);
            --nova-accent: var(--color-accent, #4facfe);
            --nova-text: var(--color-text, #12142b);
            --nova-muted: var(--color-muted, #5c6078);
            --nova-faint: var(--color-faint, #9aa0b8);
            --nova-surface: var(--color-surface, #ffffff);
            --nova-surface-alt: var(--color-surface-alt, #f7f7fb);
            --nova-surface-soft: var(--color-surface-soft, #f1f1f8);
            --nova-ink: var(--color-ink, #0c1022);
            --nova-ink-deep: var(--color-ink-deep, #070a18);
            --nova-border: var(--color-border, #e4e4f0);
            --nova-border-dark: var(--color-border-dark, #1d2142);
            --nova-success: var(--color-success, #0d9668);
            --nova-warning: var(--color-warning, #c07817);

            /* Surfaces a builder style setting can name. Two readings, so a
               card set on a light band and the same card set on a dark one
               are one setting rather than two documents. */
            --nova-card: var(--color-surface, #ffffff);
            --nova-card-invert: #131735;
            --nova-border-invert: #262b52;

            --nova-max: var(--max-width, 1240px);
            --nova-gutter: var(--gutter, 32px);
            --nova-radius: var(--radius, 14px);
            --nova-radius-lg: var(--radius-large, 24px);
            --nova-band: var(--band-padding, 110px);

            --nova-display: var(--display-family, Figtree, ui-sans-serif, system-ui, sans-serif);
            --nova-body: var(--body-family, Inter, ui-sans-serif, system-ui, sans-serif);
            --nova-display-weight: var(--display-weight, 700);

            --nova-shadow-sm: 0 2px 8px rgba(18, 20, 43, 0.05);
            --nova-shadow-md: 0 12px 40px rgba(18, 20, 43, 0.08);
            --nova-shadow-lg: 0 30px 80px rgba(18, 20, 43, 0.14);
            --nova-ease: cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* The dark reading of the two surfaces that are not tokens: they
           describe a card ON a band, and a band already carries a scheme. */
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --nova-card: #131735;
                --nova-border: #262b52;
            }
        }
        :root[data-theme="dark"] {
            --nova-card: #131735;
            --nova-border: #262b52;
        }

        html { font-size: var(--base-size, 16px); scroll-behavior: smooth; }

        body {
            font-family: var(--nova-body);
            color: var(--nova-text);
            background: var(--nova-surface-alt);
            line-height: 1.65;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        img, svg { display: block; max-width: 100%; }
        a { color: inherit; text-decoration: none; }
        ::selection { background: var(--nova-primary); color: #fff; }

        h1, h2, h3, h4, h5 {
            font-family: var(--nova-display);
            font-weight: var(--nova-display-weight);
            letter-spacing: -0.03em;
            line-height: 1.12;
            color: inherit;
        }
        h1 { font-size: clamp(38px, 5.4vw, 64px); }
        h2 { font-size: clamp(30px, 4vw, 46px); }
        h3 { font-size: clamp(19px, 2vw, 22px); letter-spacing: -0.02em; }
        h4 { font-size: 16px; letter-spacing: -0.01em; }

        a:focus-visible, button:focus-visible, summary:focus-visible {
            outline: 3px solid var(--nova-accent);
            outline-offset: 3px;
            border-radius: 6px;
        }

        .nova-skip {
            position: absolute; left: -9999px; top: 0; z-index: 6000;
            background: var(--nova-primary); color: #fff; padding: 12px 20px; border-radius: 0 0 10px 0;
        }
        .nova-skip:focus { left: 0; }

        .nova-wrap {
            width: 100%; max-width: var(--nova-max); margin: 0 auto;
            padding-left: var(--nova-gutter); padding-right: var(--nova-gutter);
        }

        /* ================= icons ================= */
        .nova-i { width: 1em; height: 1em; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ================= buttons ================= */
        .magna-btn, .nova-btn {
            position: relative; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            padding: 15px 30px; border-radius: 100px; font-weight: 600; font-size: 14px; line-height: 1;
            font-family: var(--nova-body); border: 1px solid transparent; cursor: pointer; white-space: nowrap;
            transition: transform 0.35s var(--nova-ease), box-shadow 0.35s var(--nova-ease),
                        background-color 0.35s var(--nova-ease), color 0.35s var(--nova-ease),
                        border-color 0.35s var(--nova-ease), filter 0.35s var(--nova-ease);
        }
        .magna-btn--sm, .nova-btn--sm { padding: 11px 22px; font-size: 13px; }
        .magna-btn--lg { padding: 18px 36px; font-size: 15px; }

        .magna-btn--primary, .nova-btn--primary {
            background: linear-gradient(120deg, var(--nova-primary), var(--nova-primary-alt));
            color: #fff;
            box-shadow: 0 10px 30px -8px rgba(99, 102, 241, 0.35);
        }
        .magna-btn--primary:hover, .nova-btn--primary:hover {
            filter: brightness(1.08); transform: translateY(-3px);
            box-shadow: 0 18px 40px -10px rgba(99, 102, 241, 0.45);
        }
        .magna-btn--secondary {
            background: var(--nova-text); color: var(--nova-surface);
        }
        .magna-btn--secondary:hover { transform: translateY(-3px); box-shadow: var(--nova-shadow-md); }
        .magna-btn--outline {
            background: var(--nova-surface); color: var(--nova-text); border-color: var(--nova-border);
        }
        .magna-btn--outline:hover { border-color: var(--nova-primary); color: var(--nova-primary); transform: translateY(-3px); box-shadow: var(--nova-shadow-md); }
        .magna-btn--ghost {
            background: rgba(255, 255, 255, 0.08); color: #fff; border-color: rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(6px);
        }
        .magna-btn--ghost:hover { background: rgba(255, 255, 255, 0.16); border-color: rgba(255, 255, 255, 0.42); }
        /* A ghost button on a light band would be white-on-white. */
        .nova-light .magna-btn--ghost { background: var(--nova-surface); color: var(--nova-text); border-color: var(--nova-border); }
        .nova-light .magna-btn--ghost:hover { border-color: var(--nova-primary); color: var(--nova-primary); }

        .magna-block--button { display: inline-block; }
        .magna-block--button + .magna-block--button { margin-left: 12px; }

        /* ================= header ================= */
        .nova-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            padding: 20px 0; transition: background 0.4s var(--nova-ease), box-shadow 0.4s var(--nova-ease), padding 0.4s var(--nova-ease);
        }
        .nova-header.is-stuck {
            padding: 12px 0; background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(18px); box-shadow: var(--nova-shadow-sm);
        }
        .nova-header__inner { display: flex; align-items: center; justify-content: space-between; gap: 28px; }

        .nova-logo { display: inline-flex; align-items: center; gap: 11px; font-family: var(--nova-display); font-size: 24px; font-weight: 700; color: #fff; letter-spacing: -0.04em; }
        .nova-logo svg { width: 34px; height: 34px; flex-shrink: 0; }
        .nova-logo__suffix { font-weight: 500; color: var(--nova-accent); font-size: 15px; margin-left: 2px; letter-spacing: 0.08em; text-transform: uppercase; }
        .nova-header.is-stuck .nova-logo { color: var(--nova-text); }

        .nova-nav ul { display: flex; align-items: center; gap: 30px; list-style: none; }
        .nova-nav a { font-size: 14.5px; font-weight: 500; color: rgba(255, 255, 255, 0.78); transition: color 0.25s; }
        .nova-nav a:hover { color: #fff; }
        .nova-header.is-stuck .nova-nav a { color: var(--nova-muted); }
        .nova-header.is-stuck .nova-nav a:hover { color: var(--nova-text); }

        .nova-burger { display: none; flex-direction: column; gap: 5px; background: none; border: 0; padding: 8px; }
        .nova-burger span { display: block; width: 24px; height: 2px; border-radius: 2px; background: #fff; transition: background 0.3s; }
        .nova-header.is-stuck .nova-burger span { background: var(--nova-text); }

        .nova-drawer {
            position: fixed; inset: 0; z-index: 1500; background: var(--nova-ink);
            display: flex; flex-direction: column; padding: 26px var(--nova-gutter) 40px;
            transform: translateX(100%); transition: transform 0.45s var(--nova-ease); visibility: hidden;
        }
        .nova-drawer.is-open { transform: translateX(0); visibility: visible; }
        .nova-drawer__top { display: flex; align-items: center; justify-content: space-between; }
        .nova-drawer__close { background: none; border: 0; color: #fff; font-size: 30px; line-height: 1; padding: 4px 10px; }
        .nova-drawer__links { display: grid; gap: 4px; margin-top: 48px; }
        .nova-drawer__links a {
            display: flex; align-items: center; gap: 16px; padding: 16px 0; color: #fff;
            font-family: var(--nova-display); font-size: 26px; font-weight: 600; letter-spacing: -0.03em;
            border-bottom: 1px solid var(--nova-border-dark);
        }
        .nova-drawer__index { font-family: var(--nova-body); font-size: 12px; font-weight: 600; color: var(--nova-accent); letter-spacing: 0.1em; }

        /* ================= progress + preloader ================= */
        .nova-progress { position: fixed; top: 0; left: 0; height: 3px; width: 0; z-index: 2000;
            background: linear-gradient(90deg, var(--nova-accent), var(--nova-primary), var(--nova-primary-alt)); transition: width 0.1s linear; }
        .nova-splash {
            position: fixed; inset: 0; z-index: 5000; background: var(--nova-ink);
            display: flex; align-items: center; justify-content: center; flex-direction: column;
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .nova-splash.is-done { opacity: 0; visibility: hidden; }
        html.nova-seen .nova-splash { display: none; }
        .nova-splash__bar { width: 180px; height: 2px; background: var(--nova-border-dark); margin-top: 24px; overflow: hidden; border-radius: 2px; }
        .nova-splash__bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, var(--nova-accent), var(--nova-primary), var(--nova-primary-alt)); animation: nova-load 1.3s ease forwards; }
        @keyframes nova-load { to { width: 100%; } }

        .nova-top {
            position: fixed; bottom: 30px; right: 30px; z-index: 900; width: 52px; height: 52px; border-radius: 50%;
            background: linear-gradient(135deg, var(--nova-primary), var(--nova-primary-alt)); color: #fff;
            display: flex; align-items: center; justify-content: center; cursor: pointer; border: 0;
            box-shadow: 0 12px 30px -6px rgba(99, 102, 241, 0.4);
            opacity: 0; visibility: hidden; transform: translateY(20px) scale(0.8);
            transition: all 0.4s var(--nova-ease);
        }
        .nova-top.is-on { opacity: 1; visibility: visible; transform: none; }
        .nova-top svg { width: 20px; height: 20px; }

        /* ================= bands ================= */
        .magna-section { position: relative; padding: var(--nova-band) 0; }
        .magna-section__inner { width: 100%; max-width: var(--nova-max); margin: 0 auto; padding: 0 var(--nova-gutter); position: relative; z-index: 1; }
        .magna-columns { display: flex; flex-wrap: wrap; gap: clamp(28px, 4vw, 56px); }
        .magna-column { min-width: 0; max-width: 100%; }

        .nova-problem, .nova-arch, .nova-builder, .nova-headless { background: var(--nova-surface); }
        .nova-why, .nova-audiences { background: var(--nova-surface-alt); }
        .nova-plugins, .nova-faq { background: var(--nova-surface-soft); }
        .nova-problem { border-bottom: 1px solid var(--nova-border); }
        .nova-arch, .nova-builder { border-top: 1px solid var(--nova-border); }

        .nova-cases, .nova-hero, .nova-trust, .nova-cta { background: var(--nova-ink); color: #fff; overflow: hidden; }
        .nova-cta { background: var(--nova-ink-deep); text-align: center; }
        .nova-cases h1, .nova-cases h2, .nova-cases h3, .nova-cases h4,
        .nova-hero h1, .nova-hero h2, .nova-hero h3, .nova-hero h4,
        .nova-cta h1, .nova-cta h2, .nova-cta h3, .nova-cta h4 { color: #fff; }
        .nova-cases .magna-prose p, .nova-hero .magna-prose p, .nova-cta .magna-prose p { color: #c3c8de; }

        /* A head band and the grid beneath it are one composition split
           across two sections, because a section's column spans must sum to
           12 and a full-width head plus two halves cannot. */
        .nova-continue--head { padding-bottom: 0; }
        .nova-continue--tail { padding-top: 44px; }

        .nova-center, .nova-center .magna-heading, .nova-center .magna-prose { text-align: center; }
        .nova-center .magna-prose { margin-left: auto; margin-right: auto; }
        .nova-center .magna-block--button { display: inline-block; }

        .nova-split > .magna-section__inner > .magna-columns { align-items: center; }

        /* ================= block rhythm ================= */
        .magna-block { margin-bottom: 22px; max-width: 100%; }
        .magna-block:last-child { margin-bottom: 0; }
        .magna-block--heading { margin-bottom: 14px; }
        .magna-block--spacer + .magna-block { margin-top: 0; }

        /* The eyebrow pill. A heading block set to H6 — a documented Nova
           convention, so an editor makes one without leaving the builder. */
        .magna-heading h6 {
            display: inline-flex; align-items: center; gap: 8px; padding: 7px 16px;
            background: rgba(99, 102, 241, 0.09); color: var(--nova-primary); border-radius: 100px;
            font-family: var(--nova-body); font-size: 12px; font-weight: 700; line-height: 1.4;
            letter-spacing: 0.08em; text-transform: uppercase;
            border: 1px solid rgba(99, 102, 241, 0.16);
        }
        .nova-hero .magna-heading h6, .nova-cases .magna-heading h6, .nova-cta .magna-heading h6 {
            background: rgba(255, 255, 255, 0.08); color: var(--nova-accent); border-color: rgba(255, 255, 255, 0.16);
        }
        .magna-heading--center { text-align: center; }
        .magna-heading--right { text-align: right; }

        /* ================= prose ================= */
        .magna-prose { max-width: 780px; }
        .magna-prose > * + * { margin-top: 18px; }
        .magna-prose p { color: var(--nova-muted); font-size: 16.5px; }
        .magna-prose h1, .magna-prose h2, .magna-prose h3 { color: var(--nova-text); max-width: 18ch; }
        .magna-prose h2 { max-width: 22ch; }
        .magna-prose h1 + p, .magna-prose h2 + p, .magna-prose h3 + p { margin-top: 18px; }
        .magna-prose h3 { margin-top: 40px; }
        .magna-prose em { font-style: normal; font-weight: 800; color: var(--nova-primary); }
        .nova-hero .magna-prose em, .nova-cases .magna-prose em, .nova-cta .magna-prose em { color: var(--nova-accent); }
        .nova-hero .magna-prose h1, .nova-cases .magna-prose h2, .nova-cta .magna-prose h2 { color: #fff; }
        .magna-prose a { color: var(--nova-primary); font-weight: 500; }
        .magna-prose a:hover { text-decoration: underline; }
        .magna-prose ul, .magna-prose ol { padding-left: 22px; color: var(--nova-muted); }
        .magna-prose li + li { margin-top: 8px; }
        .magna-prose strong { color: var(--nova-text); font-weight: 600; }
        .magna-prose code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.92em;
            background: var(--nova-surface-soft); padding: 0.15em 0.4em; border-radius: 5px;
        }
        .nova-center .magna-prose h1, .nova-center .magna-prose h2, .nova-center .magna-prose h3 { max-width: none; }
        .nova-cta .magna-prose { max-width: 700px; margin: 0 auto; }
        .nova-cta .magna-prose h2 { max-width: none; font-size: clamp(34px, 5vw, 54px); }
        .nova-cta .magna-prose p { font-size: 18px; }

        /* ================= quote ================= */
        .magna-quote { margin: 34px 0; }
        .magna-quote__text {
            font-family: var(--nova-display); font-size: clamp(19px, 2.2vw, 23px); font-weight: 600;
            line-height: 1.45; letter-spacing: -0.02em; color: var(--nova-text);
            border-left: 3px solid var(--nova-primary); padding-left: 22px;
        }
        .nova-cases .magna-quote__text, .nova-hero .magna-quote__text { color: #fff; border-left-color: var(--nova-accent); }
        .magna-quote__attribution { margin-top: 12px; padding-left: 25px; color: var(--nova-faint); font-size: 14px; }
        .magna-quote__source { font-style: normal; }

        /* ================= features: the marketing workhorse ================= */
        .magna-features { display: grid; gap: 22px; }
        .magna-features--icon-grid { grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); }
        .magna-features--horizontal-list { grid-template-columns: 1fr; gap: 14px; }
        .nova-grid-2 .magna-features--icon-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); }

        .magna-features__item { min-width: 0; }
        .magna-features__body { min-width: 0; flex: 1 1 auto; }
        .magna-features__title { font-family: var(--nova-display); font-size: 18px; font-weight: 700; letter-spacing: -0.02em; }
        .magna-features__description { color: var(--nova-muted); font-size: 14.5px; margin-top: 6px; }
        .nova-cases .magna-features__description, .nova-hero .magna-features__description { color: #a9b0cb; }

        .magna-features__icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; border-radius: 12px; margin-bottom: 16px;
            background: rgba(99, 102, 241, 0.1); color: var(--nova-primary);
        }
        .magna-features__icon .nova-i { width: 22px; height: 22px; }

        /* Cards — the "why Magna" grid. */
        .nova-cards .magna-features__item {
            background: var(--nova-card); border: 1px solid var(--nova-border);
            border-radius: var(--nova-radius-lg); padding: 34px 32px;
            transition: transform 0.35s var(--nova-ease), box-shadow 0.35s var(--nova-ease), border-color 0.35s var(--nova-ease);
        }
        .nova-cards .magna-features__item:hover { transform: translateY(-6px); border-color: rgba(99, 102, 241, 0.4); box-shadow: var(--nova-shadow-md); }

        /* Chips — the compact "what you can build" grid. */
        .nova-chips .magna-features { grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .nova-chips .magna-features__item {
            background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--nova-radius); padding: 24px 22px;
            transition: transform 0.35s var(--nova-ease), background 0.35s var(--nova-ease), border-color 0.35s var(--nova-ease);
        }
        .nova-chips .magna-features__item:hover { transform: translateY(-5px); background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.24); }
        .nova-chips .magna-features__title { font-size: 16px; }
        .nova-chips .magna-features__description { font-size: 13.5px; }
        .nova-light.nova-chips .magna-features__item { background: var(--nova-card); border-color: var(--nova-border); }

        /* Rows — a feature list with a status pill on the right. */
        .nova-rows .magna-features { grid-template-columns: 1fr; gap: 14px; }
        .nova-rows .magna-features__item {
            display: flex; gap: 18px; align-items: flex-start; justify-content: space-between;
            padding: 22px 24px; background: var(--nova-card); border: 1px solid var(--nova-border);
            border-radius: var(--nova-radius); transition: border-color 0.3s var(--nova-ease), box-shadow 0.3s var(--nova-ease);
        }
        .nova-rows .magna-features__item:hover { border-color: var(--nova-primary); box-shadow: var(--nova-shadow-sm); }
        .nova-rows .magna-features__title { font-size: 16px; }
        .nova-rows .magna-features__description { font-size: 14px; margin-top: 3px; }

        .magna-features__status {
            flex-shrink: 0; margin-top: 2px; display: inline-flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
            border-radius: 100px; padding: 4px 12px; white-space: nowrap;
            background: rgba(99, 102, 241, 0.1); color: var(--nova-primary); border: 1px solid rgba(99, 102, 241, 0.18);
        }
        .magna-features__status[data-tone="avail"] { background: rgba(13, 150, 104, 0.1); color: var(--nova-success); border-color: rgba(13, 150, 104, 0.24); }
        .magna-features__status[data-tone="dev"] { background: rgba(192, 120, 23, 0.1); color: var(--nova-warning); border-color: rgba(192, 120, 23, 0.24); }

        /* Steps — the numbered "install / build / trust" run. */
        .nova-steps .magna-features { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 34px; text-align: center; }
        .nova-steps .magna-features__icon {
            width: 58px; height: 58px; border-radius: 50%; margin: 0 auto 18px;
            font-family: var(--nova-display); font-size: 22px; font-weight: 800; color: #fff;
            background: linear-gradient(135deg, var(--nova-primary), var(--nova-primary-alt));
            box-shadow: 0 10px 26px -8px rgba(99, 102, 241, 0.5);
        }

        /* Layers — the architecture stack. */
        .nova-layers .magna-features, .nova-arch .magna-column:last-child .magna-features { grid-template-columns: 1fr; gap: 10px; }
        .nova-layers .magna-features__item, .nova-arch .magna-column:last-child .magna-features__item {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 18px 20px; border-radius: var(--nova-radius);
            background: var(--nova-surface-alt); border: 1px solid var(--nova-border);
        }
        .nova-layers .magna-features__item:nth-child(3),
        .nova-arch .magna-column:last-child .magna-features__item:nth-child(3) {
            background: linear-gradient(120deg, rgba(99, 102, 241, 0.12), rgba(139, 92, 246, 0.12));
            border-color: rgba(99, 102, 241, 0.35);
        }
        .magna-features__tag {
            flex-shrink: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--nova-primary); background: rgba(99, 102, 241, 0.1); border-radius: 100px; padding: 4px 11px;
        }

        /* Checklists and split-list rows — an icon beside a line of text. */
        .magna-features--horizontal-list .magna-features__item { display: flex; gap: 14px; align-items: flex-start; }
        .magna-features--horizontal-list .magna-features__icon { width: 34px; height: 34px; border-radius: 9px; margin: 0; flex-shrink: 0; }
        .magna-features--horizontal-list .magna-features__icon .nova-i { width: 17px; height: 17px; }
        .magna-features--horizontal-list .magna-features__body { min-width: 0; }
        .magna-features--horizontal-list .magna-features__title { font-size: 15.5px; font-weight: 600; }
        .nova-hero .magna-features--horizontal-list {
            display: flex; flex-wrap: wrap; gap: 12px 26px; margin-top: 30px;
        }
        .nova-hero .magna-features--horizontal-list .magna-features__item { align-items: center; gap: 9px; }
        .nova-hero .magna-features--horizontal-list .magna-features__icon {
            width: 20px; height: 20px; border-radius: 50%; background: rgba(79, 172, 254, 0.16); color: var(--nova-accent);
        }
        .nova-hero .magna-features--horizontal-list .magna-features__icon .nova-i { width: 12px; height: 12px; }
        .nova-hero .magna-features--horizontal-list .magna-features__title { font-family: var(--nova-body); font-size: 14px; font-weight: 500; color: #b9c0d8; letter-spacing: 0; }

        /* Marquee — the use-case ribbon under the hero. */
        .nova-marquee { padding: 0 0 60px; }
        .nova-marquee .magna-heading h6 { margin-bottom: 22px; }
        .nova-marquee .magna-features {
            display: flex; flex-wrap: wrap; justify-content: center; gap: 12px 34px;
        }
        .nova-marquee .magna-features__item { display: block; }
        .nova-marquee .magna-features__title {
            font-family: var(--nova-display); font-size: clamp(18px, 2.4vw, 26px); font-weight: 700;
            color: rgba(255, 255, 255, 0.28); letter-spacing: -0.02em; white-space: nowrap;
            transition: color 0.3s var(--nova-ease);
        }
        .nova-marquee .magna-features__item:hover .magna-features__title { color: rgba(255, 255, 255, 0.7); }
        .nova-marquee .magna-features__icon { display: none; }

        /* ================= hero ================= */
        .nova-hero { padding: 170px 0 90px; }
        .nova-hero::before {
            content: ""; position: absolute; inset: 0;
            background-image: linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 64px 64px;
            mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, #000 30%, transparent 75%);
        }
        .nova-hero::after {
            content: ""; position: absolute; top: -220px; left: 50%; width: 900px; height: 620px;
            transform: translateX(-50%);
            background: radial-gradient(circle, rgba(99, 102, 241, 0.35) 0%, transparent 62%);
            filter: blur(30px); pointer-events: none;
        }
        .nova-hero--short { padding: 165px 0 90px; min-height: 0; }
        .nova-hero .magna-prose { max-width: 44rem; }
        .nova-hero .magna-prose p { font-size: 18px; color: #c3c8de; }
        .nova-hero .magna-block--button { margin-top: 8px; }

        /* ================= cta ================= */
        .nova-cta { padding: 120px 0; }
        .nova-cta::before {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(600px circle at 50% 0%, rgba(99, 102, 241, 0.26), transparent 55%);
        }
        .nova-cta .magna-block--button { margin-top: 10px; }

        /* ================= containers as cards ================= */
        .magna-container { min-width: 0; }
        /* Containers nested in a container are a card grid: each one takes a
           column and wraps. This is what makes a two-up or four-up card row
           possible from the builder without a bespoke block. */
        .magna-container > .magna-block--container { flex: 1 1 min(100%, 300px); }
        /* nova-cardgrid pins that to two-up, which is the proportion the
           long-form cards on this site were written for. The maths assumes
           the wrapping container's gap is 22px — the value the starter
           documents set, and the one an editor sees in the inspector. */
        .nova-cardgrid .magna-container { text-align: left; }
        .nova-cardgrid .magna-container > .magna-block--container { flex: 0 1 calc(50% - 11px); }
        @media (max-width: 760px) {
            .nova-cardgrid .magna-container > .magna-block--container { flex: 1 1 100%; }
        }
        .magna-container .magna-heading h6 {
            background: none; border: 0; padding: 0; color: var(--nova-primary);
            font-size: 12px; letter-spacing: 0.1em;
        }
        .nova-hero .magna-container .magna-heading h6, .nova-cases .magna-container .magna-heading h6 { color: var(--nova-accent); }
        .nova-hero .magna-container, .nova-cases .magna-container { color: #d7dbec; }
        .nova-hero .magna-container h3, .nova-cases .magna-container h3 { color: #fff; }
        .nova-hero .magna-container .magna-features__title { color: #d7dbec; font-weight: 500; }

        /* ================= code / terminal ================= */
        .magna-code {
            width: 100%;
            background: var(--nova-ink); border: 1px solid var(--nova-border-dark);
            border-radius: var(--nova-radius-lg); overflow: hidden; box-shadow: var(--nova-shadow-lg);
        }
        .magna-code__filename {
            display: flex; align-items: center; gap: 8px; padding: 14px 18px;
            background: rgba(255, 255, 255, 0.03); border-bottom: 1px solid var(--nova-border-dark);
            color: #7d8399; font-size: 12.5px; letter-spacing: 0.02em;
        }
        .magna-code__filename::before {
            content: ""; width: 42px; height: 10px; flex-shrink: 0; border-radius: 20px;
            background: radial-gradient(circle at 5px 5px, #ff5f57 4px, transparent 4px),
                        radial-gradient(circle at 21px 5px, #febc2e 4px, transparent 4px),
                        radial-gradient(circle at 37px 5px, #28c840 4px, transparent 4px);
        }
        .magna-code__pre { margin: 0; padding: 26px 24px; overflow-x: auto; }
        .magna-code__pre code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13.5px; line-height: 1.85; color: #c9d1e4; white-space: pre;
        }

        /* ================= table / comparison ================= */
        .magna-table {
            overflow-x: auto; border: 1px solid var(--nova-border); border-radius: var(--nova-radius-lg);
            background: var(--nova-card); box-shadow: var(--nova-shadow-sm);
        }
        .magna-table__table { width: 100%; border-collapse: collapse; min-width: 640px; font-size: 14.5px; }
        .magna-table__table th, .magna-table__table td { padding: 18px 22px; text-align: left; border-bottom: 1px solid var(--nova-border); vertical-align: top; }
        .magna-table__table thead th { background: var(--nova-surface-soft); font-size: 12.5px; letter-spacing: 0.05em; text-transform: uppercase; font-family: var(--nova-body); }
        .magna-table__table tbody tr:last-child td { border-bottom: 0; }
        .magna-table__table td:first-child { font-weight: 600; }
        .magna-table__table td:last-child { color: var(--nova-text); font-weight: 600; }
        .magna-table__table td:not(:first-child):not(:last-child) { color: var(--nova-muted); }
        .magna-table__caption { padding: 16px 22px; text-align: left; color: var(--nova-faint); font-size: 13px; }

        /* ================= faq ================= */
        .magna-faq { max-width: 860px; margin: 0 auto; display: grid; gap: 14px; text-align: left; }
        .magna-faq__item {
            background: var(--nova-card); border: 1px solid var(--nova-border);
            border-radius: var(--nova-radius); overflow: hidden; transition: border-color 0.3s var(--nova-ease), box-shadow 0.3s var(--nova-ease);
        }
        .magna-faq__item[open] { border-color: rgba(99, 102, 241, 0.4); box-shadow: var(--nova-shadow-sm); }
        .magna-faq__question {
            display: flex; align-items: center; justify-content: space-between; gap: 20px; cursor: pointer;
            padding: 24px 28px; font-family: var(--nova-display); font-size: 17px; font-weight: 600;
            letter-spacing: -0.02em; list-style: none;
        }
        .magna-faq__question::-webkit-details-marker { display: none; }
        .magna-faq__question::after {
            content: "+"; flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-family: var(--nova-body);
            font-size: 19px; font-weight: 400; line-height: 1;
            background: rgba(99, 102, 241, 0.1); color: var(--nova-primary); transition: transform 0.3s var(--nova-ease);
        }
        .magna-faq__item[open] .magna-faq__question::after { content: "−"; transform: rotate(180deg); }
        .magna-faq__answer { padding: 0 28px 26px; color: var(--nova-muted); font-size: 15px; }

        /* ================= remaining core blocks ================= */
        .magna-callout {
            border-radius: var(--nova-radius); padding: 20px 24px; border-left: 3px solid var(--nova-primary);
            background: var(--nova-surface-soft); color: var(--nova-muted);
        }
        .magna-callout__heading { font-weight: 700; color: var(--nova-text); margin-bottom: 6px; }
        .magna-callout--success { border-left-color: var(--nova-success); }
        .magna-callout--warning { border-left-color: var(--nova-warning); }
        .magna-callout--danger { border-left-color: #dc2626; }

        .magna-divider { border: 0; border-top: 1px solid var(--nova-border); }
        .magna-divider--icon-center { display: flex; align-items: center; gap: 14px; }
        .magna-divider--icon-center .magna-divider__line { flex: 1; }

        .magna-image figure, .magna-image { margin: 0; }
        .magna-image img { border-radius: var(--nova-radius-lg); }
        .magna-image figcaption { margin-top: 10px; color: var(--nova-faint); font-size: 13.5px; text-align: center; }

        .magna-gallery { display: grid; gap: 14px; }
        .magna-gallery img { border-radius: var(--nova-radius); }

        .magna-spacer--xs { height: 8px; }
        .magna-spacer--sm { height: 18px; }
        .magna-spacer--md { height: 34px; }
        .magna-spacer--lg { height: 60px; }
        .magna-spacer--xl { height: 90px; }
        .magna-spacer--2xl { height: 130px; }

        .magna-stats { display: grid; gap: 26px; text-align: center; }
        .magna-stats--cols-2 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .magna-stats--cols-3 { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
        .magna-stats--cols-4 { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .magna-stats__value { display: block; font-family: var(--nova-display); font-size: clamp(34px, 4vw, 48px); font-weight: 800; letter-spacing: -0.04em;
            background: linear-gradient(120deg, var(--nova-primary), var(--nova-primary-alt)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .magna-stats__label { display: block; color: var(--nova-muted); font-size: 14px; margin-top: 6px; }

        .magna-pricing { display: grid; gap: 24px; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); }
        .magna-pricing__tier { background: var(--nova-card); border: 1px solid var(--nova-border); border-radius: var(--nova-radius-lg); padding: 34px 30px; display: flex; flex-direction: column; gap: 14px; }
        .magna-pricing__tier--highlighted { border-color: var(--nova-primary); box-shadow: var(--nova-shadow-md); }
        .magna-pricing__amount { font-family: var(--nova-display); font-size: 42px; font-weight: 800; letter-spacing: -0.04em; }
        .magna-pricing__period { color: var(--nova-faint); font-size: 14px; }
        .magna-pricing__features { list-style: none; display: grid; gap: 10px; color: var(--nova-muted); font-size: 14.5px; }
        .magna-pricing__features li::before { content: "✓"; color: var(--nova-success); font-weight: 700; margin-right: 8px; }
        .magna-pricing .magna-btn { margin-top: auto; }

        .magna-testimonials { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .magna-testimonials__item { background: var(--nova-card); border: 1px solid var(--nova-border); border-radius: var(--nova-radius-lg); padding: 30px 28px; }
        .magna-testimonials__quote { font-family: var(--nova-display); font-size: 17px; font-weight: 500; line-height: 1.55; }
        .magna-testimonials__author { margin-top: 16px; color: var(--nova-faint); font-size: 14px; }

        .magna-team { display: grid; gap: 26px; }
        .magna-team--cols-2 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .magna-team--cols-3 { grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .magna-team--cols-4 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .magna-team__photo { border-radius: var(--nova-radius-lg); margin-bottom: 14px; }
        .magna-team__role { color: var(--nova-primary); font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
        .magna-team__bio { color: var(--nova-muted); font-size: 14px; margin-top: 8px; }

        .magna-logos { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 34px; }
        .magna-logos--grayscale img { filter: grayscale(1); opacity: 0.6; transition: filter 0.3s, opacity 0.3s; }
        .magna-logos--grayscale a:hover img, .magna-logos--grayscale .magna-logos__item:hover img { filter: none; opacity: 1; }
        .magna-logos img { max-height: 34px; width: auto; }

        .magna-icon { color: var(--nova-primary); }
        .magna-icon__glyph { fill: none; stroke: currentColor; stroke-width: 1.6; }

        .magna-nav__list { list-style: none; display: flex; flex-wrap: wrap; gap: 12px 24px; }
        .magna-nav__list--depth-0 { flex-direction: column; gap: 13px; }
        .magna-nav a { color: var(--nova-faint); font-size: 14px; transition: color 0.2s, padding-left 0.2s; }
        .magna-nav a:hover { color: var(--nova-accent); padding-left: 5px; }
        .magna-nav__description { display: block; color: var(--nova-faint); font-size: 12.5px; }

        .magna-search { display: flex; gap: 10px; }
        .magna-search input {
            flex: 1; padding: 14px 18px; border-radius: 100px; border: 1px solid var(--nova-border);
            background: var(--nova-card); color: inherit; font: inherit; font-size: 14px;
        }

        .magna-block--video iframe, .magna-block--video video, .magna-embed iframe { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: var(--nova-radius-lg); }

        .magna-file { display: inline-flex; align-items: center; gap: 12px; padding: 14px 20px; border: 1px solid var(--nova-border); border-radius: var(--nova-radius); background: var(--nova-card); }

        .magna-entries, .magna-loop { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }

        /* ================= footer ================= */
        .nova-footer { background: var(--nova-ink-deep); color: var(--nova-faint); font-size: 14px; border-top: 1px solid var(--nova-border-dark); }
        .nova-footer .magna-section { padding: 84px 0 40px; background: none; border: 0; }
        .nova-footer h1, .nova-footer h2, .nova-footer h3, .nova-footer h4 { color: #fff; font-size: 14px; letter-spacing: 0.02em; }
        .nova-footer .magna-heading h6 { background: none; border: 0; padding: 0; color: #fff; font-size: 14px; letter-spacing: 0.02em; text-transform: none; font-family: var(--nova-display); font-weight: 700; }
        .nova-footer .magna-prose p { color: var(--nova-faint); font-size: 14px; }
        .nova-footer .magna-columns { gap: 48px; }
        .nova-footer .magna-logo__text { font-family: var(--nova-display); font-size: 24px; font-weight: 700; color: #fff; letter-spacing: -0.04em; }
        .nova-footer .nova-footer-bottom { padding: 0 0 34px; }
        .nova-footer .nova-footer-bottom .magna-section__inner { border-top: 1px solid var(--nova-border-dark); padding-top: 30px; }
        .nova-footer .nova-footer-bottom .magna-prose { max-width: none; }
        .nova-footer .nova-footer-bottom .magna-prose p { font-size: 13px; }
        .nova-footer .nova-footer-main { padding-bottom: 46px; }
        .nova-footer__fallback { padding: 60px 0 40px; }
        .nova-footer__bottom { display: flex; flex-wrap: wrap; gap: 14px; justify-content: space-between; align-items: center;
            padding: 30px 0; margin-top: 20px; border-top: 1px solid var(--nova-border-dark); font-size: 13px; }
        .nova-footer a:hover { color: #fff; }

        /* ================= frontend pages from plugins ================= */
        .magna-frontend-page { padding: 150px 0 90px; }
        .magna-frontend-page > * { max-width: var(--nova-max); margin-inline: auto; padding-inline: var(--nova-gutter); }

        /* ================= reveal-on-scroll ================= */
        .nova-reveal { opacity: 0; transform: translateY(28px); transition: opacity 0.7s var(--nova-ease), transform 0.7s var(--nova-ease); }
        .nova-reveal.is-in { opacity: 1; transform: none; }

        /* ================= responsive ================= */
        @media (max-width: 1024px) {
            .magna-columns { flex-direction: column; }
            .magna-column { flex: 1 1 100% !important; }
            /* Stacked, the columns must STRETCH. Centring them makes each
               one fit-content, and a fit-content column is as wide as its
               widest unbreakable child — which is how a code block ends up
               pushing a stacked column past the page gutter. */
            .nova-split > .magna-section__inner > .magna-columns { align-items: stretch; }
            .nova-hero { padding-bottom: 70px; }
        }
        @media (max-width: 900px) {
            .nova-nav, .nova-header__cta { display: none; }
            .nova-burger { display: flex; }
        }
        @media (max-width: 680px) {
            :root { --nova-gutter: 22px; --nova-band: 80px; }
            .nova-rows .magna-features__item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .magna-block--button + .magna-block--button { margin-left: 0; margin-top: 10px; }
            .nova-top { bottom: 18px; right: 18px; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .nova-reveal { opacity: 1 !important; transform: none !important; }
        }
    </style>
</head>
<body>

<a class="nova-skip" href="#main">Skip to content</a>

{{-- The icon sprite. Block views reference these by name through <use>, so
     a document stores an icon NAME and never markup — the same rule the
     core icon registry enforces, kept here because a theme cannot register
     icons of its own. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="nova-mark-gradient" x1="0" y1="0" x2="34" y2="34" gradientUnits="userSpaceOnUse">
            <stop stop-color="#6366f1"/>
            <stop offset="1" stop-color="#8b5cf6"/>
        </linearGradient>
        <symbol id="nova-mark" viewBox="0 0 34 34">
            <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#nova-mark-gradient)" stroke-width="2.4" fill="rgba(99,102,241,.06)"/>
            <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#nova-mark-gradient)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </symbol>
        <symbol id="nova-i-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
        <symbol id="nova-i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/></symbol>
        <symbol id="nova-i-code" viewBox="0 0 24 24"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></symbol>
        <symbol id="nova-i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
        <symbol id="nova-i-bolt" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></symbol>
        <symbol id="nova-i-modules" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="nova-i-pencil" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></symbol>
        <symbol id="nova-i-screen" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
        <symbol id="nova-i-terminal" viewBox="0 0 24 24"><path d="M4 17l6-6-6-6M12 19h8"/></symbol>
        <symbol id="nova-i-type" viewBox="0 0 24 24"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></symbol>
        <symbol id="nova-i-layout" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="8" height="7" rx="1"/><rect x="15" y="14" width="6" height="7" rx="1"/></symbol>
        <symbol id="nova-i-layers" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5"/></symbol>
        <symbol id="nova-i-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="nova-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
        <symbol id="nova-i-building" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></symbol>
        <symbol id="nova-i-list" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
        <symbol id="nova-i-devices" viewBox="0 0 24 24"><rect x="2" y="4" width="13" height="16" rx="2"/><rect x="17" y="8" width="5" height="12" rx="1.5"/></symbol>
        <symbol id="nova-i-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M18 17l-3-3-4 4-5-5"/></symbol>
        <symbol id="nova-i-users" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="nova-i-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 5 15.4a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9.4a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 8.6 5H9a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V12a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
        <symbol id="nova-i-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/></symbol>
        <symbol id="nova-i-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
        <symbol id="nova-i-arrow-up" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></symbol>
    </defs>
</svg>

{{--
    Visitor chrome, and only for a visitor.

    The builder canvas renders through this same layout — that is what keeps
    the canvas honest — so anything that covers the page on load would cover
    the canvas too. A splash screen over the thing you are editing is not a
    faithful preview, it is a blindfold.
--}}
@unless($builderMode ?? false)
    <div class="nova-splash" data-nova-splash>
        <div style="display:flex;align-items:center;gap:14px">
            <svg style="width:52px;height:52px"><use href="#nova-mark"/></svg>
            <span style="font-family:var(--nova-display);font-size:42px;font-weight:700;color:#fff;letter-spacing:-0.04em">{{ $siteName }}</span>
        </div>
        <div class="nova-splash__bar"><i></i></div>
    </div>

    <div class="nova-progress" data-nova-progress></div>
@endunless

@if(!empty($headerPartHtml))
    {{-- A site-designed header part replaces the theme's own chrome. --}}
    <header class="nova-header nova-header--custom" data-nova-header>{!! $headerPartHtml !!}</header>
@else
    <header class="nova-header" data-nova-header>
        <div class="nova-wrap nova-header__inner">
            <a href="/" class="nova-logo">
                <svg><use href="#nova-mark"/></svg>
                {{ $siteName }}
            </a>
            @if(!empty($headerMenu))
                <nav class="nova-nav" aria-label="Primary">
                    <ul>
                        @foreach($headerMenu as $item)
                            <li>
                                <a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url'] ?? null) }}"
                                   @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif>{{ $item['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
            <div class="nova-header__cta">
                <a href="#get-started" class="nova-btn nova-btn--primary nova-btn--sm">
                    Get Started
                    <svg class="nova-i" style="width:16px;height:16px"><use href="#nova-i-arrow-right"/></svg>
                </a>
            </div>
            <button class="nova-burger" type="button" aria-label="Open menu" aria-expanded="false" data-nova-burger>
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>
@endif

<div class="nova-drawer" id="nova-drawer" data-nova-drawer aria-hidden="true">
    <div class="nova-drawer__top">
        <a href="/" class="nova-logo">
            <svg><use href="#nova-mark"/></svg>
            {{ $siteName }}
        </a>
        <button class="nova-drawer__close" type="button" aria-label="Close menu" data-nova-drawer-close>&times;</button>
    </div>
    <nav class="nova-drawer__links" aria-label="Mobile" data-nova-drawer-links></nav>
</div>

<main id="main">
    @if(!empty($mainHtml))
        {!! $mainHtml !!}
    @else
        @include('magna-pages::partials.sections')
    @endif
</main>

@if(!empty($popupsHtml))
    {!! $popupsHtml !!}
@endif

@if(!empty($footerPartHtml))
    <footer class="nova-footer nova-footer--custom">{!! $footerPartHtml !!}</footer>
@else
    <footer class="nova-footer">
        <div class="nova-wrap nova-footer__fallback">
            <a href="/" class="nova-logo"><svg><use href="#nova-mark"/></svg>{{ $siteName }}</a>
        </div>
        <div class="nova-wrap">
            <div class="nova-footer__bottom">
                <span>&copy; {{ now()->year }} {{ $siteName }}</span>
                <span>Built with Magna</span>
            </div>
        </div>
    </footer>
@endif

@unless($builderMode ?? false)
    <button class="nova-top" type="button" aria-label="Back to top" data-nova-top>
        <svg class="nova-i"><use href="#nova-i-arrow-up"/></svg>
    </button>
@endunless

@unless($builderMode ?? false)
<script>
    /*
        Nova's behaviour, and all of it: sticky header, scroll progress,
        the mobile drawer, reveal-on-scroll and back-to-top. No dependency,
        no build step, and nothing a block view needs to know about — the
        theme audit forbids script inside block views precisely so that
        interactivity lives in one reviewable place.
    */
    (function () {
        var root = document.documentElement;
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        try {
            if (window.sessionStorage.getItem('nova-splash') === '1') {
                root.classList.add('nova-seen');
            }
        } catch (e) {}

        function ready(fn) {
            if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
        }

        ready(function () {
            var splash = document.querySelector('[data-nova-splash]');
            if (splash) {
                window.setTimeout(function () {
                    splash.classList.add('is-done');
                    try { window.sessionStorage.setItem('nova-splash', '1'); } catch (e) {}
                }, root.classList.contains('nova-seen') ? 0 : 1300);
            }

            var header = document.querySelector('[data-nova-header]');
            var progress = document.querySelector('[data-nova-progress]');
            var toTop = document.querySelector('[data-nova-top]');

            function onScroll() {
                var y = window.scrollY || window.pageYOffset;
                if (header) { header.classList.toggle('is-stuck', y > 40); }
                if (toTop) { toTop.classList.toggle('is-on', y > 600); }
                if (progress) {
                    var max = document.body.scrollHeight - window.innerHeight;
                    progress.style.width = (max > 0 ? (y / max) * 100 : 0) + '%';
                }
            }
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();

            if (toTop) {
                toTop.addEventListener('click', function () {
                    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
                });
            }

            /*
                The drawer copies whatever navigation the header actually
                has, so it keeps working when a site replaces the theme's
                chrome with a header part of its own design.
            */
            var drawer = document.querySelector('[data-nova-drawer]');
            var burger = document.querySelector('[data-nova-burger]');
            var links = document.querySelector('[data-nova-drawer-links]');
            if (drawer && links && header) {
                var sourceLinks = header.querySelectorAll('nav a');
                Array.prototype.forEach.call(sourceLinks, function (source, index) {
                    var a = document.createElement('a');
                    a.href = source.getAttribute('href') || '#';
                    if (source.getAttribute('target')) {
                        a.target = source.getAttribute('target');
                        a.rel = 'noopener noreferrer';
                    }
                    var number = document.createElement('span');
                    number.className = 'nova-drawer__index';
                    number.textContent = ('0' + (index + 1)).slice(-2);
                    a.appendChild(number);
                    a.appendChild(document.createTextNode(source.textContent.trim()));
                    links.appendChild(a);
                });

                var setOpen = function (open) {
                    drawer.classList.toggle('is-open', open);
                    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
                    document.body.style.overflow = open ? 'hidden' : '';
                    if (burger) { burger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
                };
                if (burger) { burger.addEventListener('click', function () { setOpen(true); }); }
                var close = document.querySelector('[data-nova-drawer-close]');
                if (close) { close.addEventListener('click', function () { setOpen(false); }); }
                links.addEventListener('click', function (event) {
                    if (event.target.closest('a')) { setOpen(false); }
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') { setOpen(false); }
                });
            }

            /* Reveal-on-scroll, applied to the blocks the renderer emitted
               rather than to markup written for it. */
            if (!reduced && 'IntersectionObserver' in window) {
                var targets = document.querySelectorAll('main .magna-section > .magna-section__inner');
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-in');
                            observer.unobserve(entry.target);
                        }
                    });
                }, { rootMargin: '0px 0px -60px 0px', threshold: 0.04 });

                Array.prototype.forEach.call(targets, function (target) {
                    target.classList.add('nova-reveal');
                    observer.observe(target);
                });
            }
        });
    })();
</script>
@endunless

</body>
</html>

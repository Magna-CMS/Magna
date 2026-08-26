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
            Nova — the Magna product site.

            This stylesheet IS the site's stylesheet: the declarations are
            the original's, unchanged, with their selectors pointed at the
            markup the block renderer emits. Where the original wrote
            `.why-card`, this writes `.why .magna-features__item` and keeps
            every value. That is the whole design of the theme — the site
            keeps its exact appearance, and every part of it is a node the
            page builder can edit.

            The variable names below are the original's too, so the rules
            that use them did not have to be touched. They read from design
            tokens, so Appearance can still repaint the site.
        */
        :root {
            --bg-main: var(--color-bg-main, #f7f7fb);
            --bg-white: var(--color-bg-white, #ffffff);
            --bg-soft: var(--color-bg-soft, #f1f1f8);
            --bg-dark: var(--color-bg-dark, #0c1022);
            --bg-darker: var(--color-bg-darker, #070a18);
            --text-main: var(--color-text-main, #12142b);
            --text-muted: var(--color-text-muted, #5c6078);
            --text-light: var(--color-text-light, #9aa0b8);
            --accent: var(--color-accent, #6366f1);
            --accent-2: var(--color-accent-2, #8b5cf6);
            --accent-3: var(--color-accent-3, #4facfe);
            --accent-hover: var(--color-accent-hover, #4f52e0);
            --accent-light: var(--color-accent-light, #eef0fe);
            --border: var(--color-border, #e4e4f0);
            --border-dark: var(--color-border-dark, #1d2142);
            --status-avail: var(--color-status-avail, #0d9668);
            --status-dev: var(--color-status-dev, #c07817);

            --maxw: var(--max-width, 1240px);
            --radius: var(--radius-base, 14px);
            --radius-lg: var(--radius-large, 24px);

            /*
                Values a design token cannot hold: the token filter drops
                anything containing "(" , which is every rgba() and every
                shadow. They live here, which is also where the original
                kept them.
            */
            --accent-glow: rgba(99,102,241,0.35);
            --shadow-sm: 0 2px 8px rgba(18,20,43,0.05);
            --shadow-md: 0 12px 40px rgba(18,20,43,0.08);
            --shadow-lg: 0 30px 80px rgba(18,20,43,0.14);
            --ease: cubic-bezier(0.16,1,0.3,1);

            /* The two translucent card surfaces, as variables so a block's
               style setting in the builder can name them — a style value
               may hold var(--token) and nothing else. */
            --card-invert: rgba(255,255,255,0.05);
            --border-invert: rgba(255,255,255,0.12);

            /* The mark, for the two places it is drawn as decoration
               rather than as content (the hero card, the architecture
               panel). A <use> reference cannot live in a pseudo-element. */
            --mark-svg: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 34 34'%3E%3ClinearGradient id='g' x1='0' y1='0' x2='34' y2='34' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%236366f1'/%3E%3Cstop offset='1' stop-color='%238b5cf6'/%3E%3C/linearGradient%3E%3Cpath d='M17 1 31 9v16l-14 8L3 25V9l14-8Z' stroke='url(%23g)' stroke-width='2.4' fill='rgba(99,102,241,.06)'/%3E%3Cpath d='M10 23V11.5l7 6 7-6V23' stroke='url(%23g)' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round' fill='none'/%3E%3C/svg%3E");
            --serif: var(--display-family, 'Figtree', system-ui, -apple-system, sans-serif);
            --sans: var(--body-family, 'Inter', system-ui, -apple-system, sans-serif);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; font-size: var(--base-size, 16px); }
        body {
            font-family: var(--sans);
            color: var(--text-main);
            background: var(--bg-main);
            line-height: 1.65;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4 { letter-spacing: -0.03em; font-weight: 700; color: var(--text-main); }
        h5, h6 { letter-spacing: -0.03em; font-weight: 700; }
        a { text-decoration: none; color: inherit; }
        img, svg.logo-mark { display: block; max-width: 100%; }
        ::selection { background: var(--accent); color: #fff; }

        .container, .magna-section__inner { width: 100%; max-width: var(--maxw); margin: 0 auto; padding: 0 32px; }
        .serif { font-family: var(--serif); font-weight: 600; }

        /* ===== LOGO ===== */
        .logo { display: inline-flex; align-items: center; gap: 11px; font-family: var(--serif); font-size: 24px; font-weight: 700; color: #fff; letter-spacing: -0.04em; transition: color 0.4s var(--ease); }
        .logo .logo-mark { width: 34px; height: 34px; flex-shrink: 0; }
        .logo .cms { font-weight: 500; color: var(--accent-3); font-size: 15px; margin-left: 2px; letter-spacing: 0.08em; text-transform: uppercase; align-self: center; margin-top: 4px; }

        /* ===== SCROLL PROGRESS ===== */
        .scroll-progress {
            position: fixed; top: 0; left: 0; height: 3px; width: 0%;
            background: linear-gradient(90deg, var(--accent-3), var(--accent), var(--accent-2));
            z-index: 2000; transition: width 0.1s linear;
        }

        /* ===== CUSTOM CURSOR ===== */
        .cursor-glow {
            position: fixed; top: 0; left: 0; width: 380px; height: 380px;
            border-radius: 50%; pointer-events: none; z-index: 1;
            background: radial-gradient(circle, var(--accent-glow) 0%, transparent 60%);
            transform: translate(-50%,-50%); opacity: 0; transition: opacity 0.4s ease;
            mix-blend-mode: multiply;
        }

        /* ===== PRELOADER ===== */
        .preloader {
            position: fixed; inset: 0; z-index: 5000; background: var(--bg-dark);
            display: flex; align-items: center; justify-content: center; flex-direction: column;
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .preloader.done { opacity: 0; visibility: hidden; }
        html.no-splash .preloader { display: none; }
        .pre-logo { display: flex; align-items: center; gap: 14px; opacity: 0; animation: preIn 0.8s var(--ease) forwards; }
        .pre-logo .logo-mark { width: 52px; height: 52px; }
        .pre-logo span { font-family: var(--serif); font-size: 42px; font-weight: 700; color: #fff; letter-spacing: -0.04em; }
        .pre-bar { width: 180px; height: 2px; background: var(--border-dark); margin-top: 24px; overflow: hidden; border-radius: 2px; }
        .pre-bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg,var(--accent-3),var(--accent),var(--accent-2)); animation: preLoad 1.3s ease forwards; }
        @keyframes preIn { to { opacity: 1; transform: translateY(0); } from { opacity: 0; transform: translateY(14px); } }
        @keyframes preLoad { to { width: 100%; } }

        /* ===== BUTTONS ===== */
        .btn, .magna-btn {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            gap: 10px; padding: 15px 30px; border-radius: 100px; font-weight: 600; font-size: 14px;
            font-family: var(--sans);
            transition: all 0.35s var(--ease); cursor: pointer; border: 1px solid transparent;
            overflow: hidden; white-space: nowrap;
        }
        .btn svg, .magna-btn svg { width: 17px; height: 17px; transition: transform 0.35s var(--ease); }
        .btn:hover svg, .magna-btn:hover svg { transform: translateX(4px); }
        .btn-primary, .magna-btn--primary { background: linear-gradient(120deg, var(--accent), var(--accent-2)); color: #fff; box-shadow: 0 10px 30px -8px var(--accent-glow); }
        .btn-primary:hover, .magna-btn--primary:hover { filter: brightness(1.08); transform: translateY(-3px); box-shadow: 0 18px 40px -10px var(--accent-glow); }
        .btn-secondary, .magna-btn--outline { background: var(--bg-white); color: var(--text-main); border-color: var(--border); }
        .btn-secondary:hover, .magna-btn--outline:hover { border-color: var(--accent); color: var(--accent); transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .btn-dark, .magna-btn--secondary { background: var(--text-main); color: #fff; }
        .btn-dark:hover, .magna-btn--secondary:hover { background: #232649; transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .btn-ghost, .magna-btn--ghost { background: rgba(255,255,255,0.08); color: #fff; border-color: rgba(255,255,255,0.2); backdrop-filter: blur(6px); }
        .btn-ghost:hover, .magna-btn--ghost:hover { background: rgba(255,255,255,0.16); border-color: rgba(255,255,255,0.4); }
        .magna-btn--sm { padding: 11px 22px; font-size: 13px; }
        .magna-btn--lg { padding: 18px 36px; font-size: 15px; }

        /* ===== BADGE (a heading block set to H6) ===== */
        .magna-heading h6 {
            display: inline-flex; align-items: center; gap: 8px; padding: 7px 16px;
            background: var(--accent-light); color: var(--accent); border-radius: 100px;
            font-family: var(--sans); font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
            border: 1px solid rgba(99,102,241,0.15);
        }
        .magna-heading h6::before { content:""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); animation: pulse 2s infinite; }
        .hero .magna-heading h6, .cases .magna-heading h6, .cta .magna-heading h6, .trust .magna-heading h6 {
            background: rgba(255,255,255,0.08); color: var(--accent-3); border-color: rgba(255,255,255,0.14);
        }
        .hero .magna-heading h6::before, .cases .magna-heading h6::before, .cta .magna-heading h6::before { background: var(--accent-3); }
        @keyframes pulse { 0%,100%{ box-shadow: 0 0 0 0 var(--accent-glow);} 50%{ box-shadow: 0 0 0 6px transparent;} }

        /* Every band's default, ahead of the bands themselves so a band
           that names its own padding (the hero, the ribbon, the closing
           call to action) keeps it. */
        .magna-section { padding: 110px 0; position: relative; }

        /* ===== HEADER ===== */
        header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            padding: 20px 0; transition: all 0.4s var(--ease);
            background: transparent;
        }
        header.scrolled {
            padding: 12px 0; background: rgba(255,255,255,0.8);
            backdrop-filter: blur(18px) saturate(180%); border-bottom: 1px solid var(--border);
            box-shadow: 0 4px 30px rgba(18,20,43,0.04);
        }
        .nav-wrap { display: flex; align-items: center; justify-content: space-between; }
        header.scrolled .logo { color: var(--text-main); }
        header nav ul { display: flex; gap: 34px; list-style: none; }
        header nav a { position: relative; color: rgba(255,255,255,0.75); font-size: 14px; font-weight: 500; transition: color 0.25s; padding: 4px 0; }
        header nav a::after { content:""; position: absolute; left: 0; bottom: -2px; width: 0; height: 2px; background: var(--accent); transition: width 0.3s var(--ease); }
        header nav a:hover { color: #fff; }
        header nav a:hover::after { width: 100%; }
        header.scrolled nav a { color: var(--text-muted); }
        header.scrolled nav a:hover { color: var(--text-main); }
        header.scrolled .hamburger span { background: var(--text-main); }
        .nav-right { display: flex; align-items: center; gap: 16px; }
        .hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; background: none; border: none; padding: 8px; }
        .hamburger span { width: 24px; height: 2px; background: #fff; border-radius: 2px; transition: all 0.3s var(--ease); }
        .hamburger.open span:nth-child(1){ transform: translateY(7px) rotate(45deg); }
        .hamburger.open span:nth-child(2){ opacity: 0; }
        .hamburger.open span:nth-child(3){ transform: translateY(-7px) rotate(-45deg); }

        /* Mobile menu */
        .mobile-menu {
            position: fixed; inset: 0; z-index: 1100;
            background: linear-gradient(160deg, #0f1330 0%, #070a18 100%);
            display: flex; flex-direction: column; padding: 20px 26px 34px;
            opacity: 0; visibility: hidden; transform: translateY(-10px);
            transition: opacity 0.45s var(--ease), visibility 0.45s var(--ease), transform 0.45s var(--ease);
        }
        .mobile-menu::before { content:""; position: absolute; inset: 0; pointer-events: none;
            background: radial-gradient(420px circle at 88% 10%, rgba(99,102,241,0.20), transparent 55%),
                        radial-gradient(380px circle at 0% 100%, rgba(79,172,254,0.10), transparent 50%); }
        .mobile-menu.open { opacity: 1; visibility: visible; transform: translateY(0); }

        .mm-top { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; padding-bottom: 8px; }
        .mm-close {
            width: 46px; height: 46px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.18);
            background: rgba(255,255,255,0.05); color: #fff; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.3s var(--ease);
        }
        .mm-close:hover { background: rgba(99,102,241,0.16); border-color: var(--accent); color: var(--accent-3); transform: rotate(90deg); }
        .mm-close svg { width: 20px; height: 20px; }

        .mm-links { position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; justify-content: center; }
        .mm-links a {
            display: flex; align-items: center; gap: 18px; padding: 15px 2px; color: #f1f5f9;
            font-family: var(--serif); font-size: clamp(26px, 8vw, 34px); font-weight: 700; letter-spacing: -0.03em;
            border-bottom: 1px solid rgba(255,255,255,0.08); opacity: 0; transform: translateX(-26px); transition: color 0.25s;
        }
        .mm-links a .mm-idx { font-family: var(--sans); font-size: 12px; font-weight: 600; color: var(--accent-3); width: 24px; letter-spacing: 0.1em; }
        .mm-links a .mm-arrow { margin-left: auto; width: 24px; height: 24px; color: var(--accent-3); opacity: 0; transform: translateX(-10px); transition: all 0.3s var(--ease); }
        .mm-links a:active, .mm-links a:hover { color: var(--accent-3); }
        .mm-links a:active .mm-arrow, .mm-links a:hover .mm-arrow { opacity: 1; transform: translateX(0); }
        .mobile-menu.open .mm-links a { animation: mmIn 0.55s var(--ease) forwards; }
        .mobile-menu.open .mm-links a:nth-child(1){ animation-delay: .10s; }
        .mobile-menu.open .mm-links a:nth-child(2){ animation-delay: .16s; }
        .mobile-menu.open .mm-links a:nth-child(3){ animation-delay: .22s; }
        .mobile-menu.open .mm-links a:nth-child(4){ animation-delay: .28s; }
        .mobile-menu.open .mm-links a:nth-child(5){ animation-delay: .34s; }
        .mobile-menu.open .mm-links a:nth-child(6){ animation-delay: .40s; }
        .mobile-menu.open .mm-links a:nth-child(7){ animation-delay: .46s; }
        .mobile-menu.open .mm-links a:nth-child(8){ animation-delay: .52s; }
        .mobile-menu.open .mm-links a:nth-child(9){ animation-delay: .58s; }
        @keyframes mmIn { to { opacity: 1; transform: none; } }

        .mm-foot { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 22px; opacity: 0; transform: translateY(18px); }
        .mobile-menu.open .mm-foot { animation: mmIn 0.55s var(--ease) 0.62s forwards; }
        .mm-foot .btn { width: 100%; }
        .mm-note { font-size: 13px; color: #9aa0b8; text-align: center; }

        /* ===== HERO ===== */
        .hero {
            position: relative; min-height: 100vh; display: flex; align-items: center;
            padding: 150px 0 90px; overflow: hidden; background: var(--bg-dark); color: #fff;
        }
        .hero::before {
            content:""; position: absolute; inset: 0; z-index: 0; opacity: 0.5;
            background-image: linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(900px circle at 50% 40%, #000 20%, transparent 75%);
            mask-image: radial-gradient(900px circle at 50% 40%, #000 20%, transparent 75%);
        }
        .hero::after {
            content:""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
            background:
              radial-gradient(620px circle at 12% 25%, rgba(99,102,241,0.22), transparent 48%),
              radial-gradient(700px circle at 88% 72%, rgba(139,92,246,0.16), transparent 48%),
              radial-gradient(460px circle at 70% 12%, rgba(79,172,254,0.10), transparent 50%);
        }
        .hero > .magna-section__inner { position: relative; z-index: 2; width: 100%; }
        .hero > .magna-section__inner > .magna-columns { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 60px; align-items: center; }

        /*
            The hero's entrance. The original staggers its four `.anim`
            children; here the children ARE the blocks, so the delays walk
            the block list instead. The base opacity is unconditional, as
            the original's is — this theme's script is already load-bearing
            (nothing dismisses the preloader without it), so a no-script
            visitor was never seeing the hero either way.
        */
@unless($builderMode ?? false)
        /* The canvas runs this layout with the behaviour script suppressed,
           so nothing there would ever add `.loaded` — and an editor would
           open the hero to find it blank. */
        .hero .magna-column > .magna-block, .hero .magna-container { opacity: 0; }
@endunless
        .hero.loaded .magna-column > .magna-block,
        .hero.loaded .magna-container { animation: fadeUp 0.9s var(--ease) both; }
        .hero.loaded .magna-column > .magna-block:nth-child(1) { animation-delay: .05s; }
        .hero.loaded .magna-column > .magna-block:nth-child(2) { animation-delay: .15s; }
        .hero.loaded .magna-column > .magna-block:nth-child(3) { animation-delay: .25s; }
        .hero.loaded .magna-column > .magna-block:nth-child(4) { animation-delay: .35s; }
        .hero.loaded .magna-column > .magna-block:nth-child(5) { animation-delay: .35s; }
        .hero.loaded .magna-column > .magna-block:nth-child(n+6) { animation-delay: .45s; }
        /* The card is also the first block of its column, so it needs to
           out-specify the nth-child stagger to keep the original's 0.3s. */
        .hero.loaded .magna-column > .magna-block--container.magna-container { animation-delay: .3s; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        .hero .magna-prose h1 { font-family: var(--serif); font-size: clamp(38px, 5.4vw, 64px); line-height: 1.06; letter-spacing: -0.04em; color: #fff; font-weight: 700; max-width: none; }
        .hero .magna-prose h1 em { font-style: normal; font-weight: 800; background: linear-gradient(100deg, var(--accent-3), var(--accent-2)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .hero .magna-prose p { color: #c3c8de; font-size: 18px; max-width: 560px; }
        .hero .magna-column > .magna-block--text + .magna-block--text { margin-top: 24px; }
        .hero .magna-column > .magna-block--text + .magna-block--button { margin-top: 34px; }
        .hero .magna-column > .magna-block--button + .magna-block--features { margin-top: 26px; }
        /* The original's hero paragraph keeps its 34px even when it is the
           last thing in the hero, which is what sets a sub-page hero's height. */
        .hero .magna-column > .magna-block--text:last-child { margin-bottom: 34px; }
        .hero.short { min-height: 56vh; padding: 175px 0 95px; }
        .hero.short > .magna-section__inner > .magna-columns { grid-template-columns: 1fr; }
        .hero.short .magna-prose { max-width: 820px; }
        .hero.short .magna-prose h1 { max-width: none; }

        /* The hero's checklist row (a features block, horizontal list). */
        .hero .magna-column > .magna-block--features.magna-features--horizontal-list { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; color: #8b91ad; }
        .hero .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item { display: inline-flex; align-items: center; gap: 7px; }
        .hero .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon { width: 14px; height: 14px; background: none; color: var(--accent-3); border-radius: 0; margin: 0; }
        .hero .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon svg { width: 14px; height: 14px; }
        .hero .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__title { font-family: var(--sans); font-size: 13px; font-weight: 400; color: #8b91ad; letter-spacing: 0; }

        /* The hero card (a container block). */
        .hero .magna-container {
            backdrop-filter: blur(20px); box-shadow: 0 30px 70px rgba(0,0,0,0.35); text-align: center;
        }
        .hero .magna-container::before {
            content:""; display: block; width: 116px; height: 116px; margin: 6px auto 22px;
            background: center / contain no-repeat var(--mark-svg);
            filter: drop-shadow(0 0 34px rgba(99,102,241,0.45));
        }
        .hero .magna-container .magna-heading h6 {
            background: none; border: 0; padding: 0; font-size: 12px; text-transform: uppercase;
            letter-spacing: 0.1em; color: var(--accent-3); font-weight: 700; font-family: var(--sans);
        }
        .hero .magna-container .magna-heading h6::before { display: none; }
        .hero .magna-container h3 { font-family: var(--serif); font-size: 23px; color: #fff; font-weight: 600; margin: 0; }
        .hero .magna-container .magna-block--heading { margin-bottom: 6px; }
        .hero .magna-container .magna-block--heading + .magna-block--heading { margin-bottom: 22px; }
        .hero .magna-container .magna-features--horizontal-list {
            display: grid; gap: 12px; text-align: left; border-top: 1px solid rgba(255,255,255,0.12); padding-top: 24px; font-size: 14px;
        }
        .hero .magna-container .magna-features__item { display: flex; align-items: center; gap: 10px; }
        .hero .magna-container .magna-features__icon { width: 16px; height: 16px; background: none; color: var(--accent-3); border-radius: 0; margin: 0; flex-shrink: 0; }
        .hero .magna-container .magna-features__icon svg { width: 16px; height: 16px; }
        .hero .magna-container .magna-features__title { font-size: 14px; color: #d6daec; font-weight: 500; font-family: var(--sans); letter-spacing: 0; }

        .scroll-hint { position: absolute; bottom: 26px; left: 50%; transform: translateX(-50%); z-index: 2; display: flex; flex-direction: column; align-items: center; gap: 8px; color: #8b91ad; font-size: 11px; letter-spacing: 0.15em; text-transform: uppercase; }
        .scroll-hint .mouse { width: 24px; height: 38px; border: 2px solid rgba(255,255,255,0.3); border-radius: 14px; position: relative; }
        .scroll-hint .mouse::before { content:""; position: absolute; top: 7px; left: 50%; transform: translateX(-50%); width: 4px; height: 8px; background: var(--accent-3); border-radius: 2px; animation: scrollWheel 1.6s infinite; }
        @keyframes scrollWheel { 0%{ opacity:1; top: 7px;} 70%{ opacity:0; top: 18px;} 100%{opacity:0;} }

        /* ===== MARQUEE ===== */
        .trust { background: var(--bg-dark); padding: 0 0 60px; overflow: hidden; }
        .trust > .magna-section__inner { max-width: none; padding: 0; }
        .trust .magna-heading { max-width: var(--maxw); margin: 0 auto; padding: 0 32px; }
        .trust .magna-block--heading { margin-bottom: 0; }
        .trust .magna-column > .magna-block--heading + .magna-block--features { margin-top: 0; }
        .trust .magna-heading h6 {
            display: block; text-align: center; background: none; border: 0; padding: 0;
            color: #676d8c; font-size: 12px; font-weight: 400; letter-spacing: 0.15em; text-transform: uppercase; margin-bottom: 26px;
        }
        .trust .magna-heading h6::before { display: none; }
        .trust .magna-column > .magna-block--features {
            display: flex; gap: 64px; padding-right: 64px; white-space: nowrap;
            animation: scrollX 30s linear infinite; width: max-content;
            -webkit-mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
            mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
        }
        .trust:hover .magna-column > .magna-block--features { animation-play-state: paused; }
        .trust .magna-column > .magna-block--features .magna-features__item { display: block; }
        .trust .magna-column > .magna-block--features .magna-features__title { font-family: var(--serif); font-size: 26px; font-weight: 500; color: rgba(255,255,255,0.26); transition: color 0.3s; letter-spacing: normal; }
        .trust .magna-column > .magna-block--features .magna-features__item:hover .magna-features__title { color: rgba(255,255,255,0.6); }
        .trust .magna-column > .magna-block--features .magna-features__icon,
        .trust .magna-column > .magna-block--features .magna-features__description { display: none; }
        @keyframes scrollX { to { transform: translateX(-50%); } }

        /* ===== SECTION SHARED ===== */
        .magna-columns { display: flex; flex-wrap: wrap; gap: 0; }
        .magna-column { min-width: 0; max-width: 100%; }
        .magna-block { max-width: 100%; }

        /*
            The rhythm the original got from wrappers (.section-head, its
            64px bottom margin, the 22px under a badge), rebuilt from the
            sibling order the renderer produces. Same numbers.
        */
        .magna-block--heading { margin-bottom: 22px; }
        .magna-block--text + .magna-block--text { margin-top: 16px; }
        .magna-column > .magna-block--text + .magna-block--features,
        .magna-column > .magna-block--text + .magna-block--faq,
        .magna-column > .magna-block--text + .magna-block--table,
        .magna-column > .magna-block--text + .magna-block--container,
        .magna-column > .magna-block--heading + .magna-block--features,
        .magna-column > .magna-block--heading + .magna-block--faq,
        .magna-column > .magna-block--heading + .magna-block--table,
        .magna-column > .magna-block--heading + .magna-block--container { margin-top: 64px; }
        .magna-column > .magna-block--features + .magna-block--text, 
        .magna-column > .magna-block--table + .magna-block--text,
        .magna-column > .magna-block--container + .magna-block--text { margin-top: 44px; }
        .magna-block--button { display: inline-flex; margin-top: 26px; }
        .magna-block--button + .magna-block--button { margin-left: 16px; }
        .magna-block--text + .magna-block--button { margin-top: 30px; }
        .magna-block--quote { margin: 28px 0 0; }

        .magna-prose { max-width: 680px; }
        .magna-prose h2 { font-family: var(--serif); font-size: clamp(30px, 4vw, 46px); line-height: 1.1; letter-spacing: -0.035em; font-weight: 700; }
        .magna-prose h2 em { font-style: normal; font-weight: 800; color: var(--accent); }
        .magna-prose h3 { font-family: var(--serif); font-size: 24px; margin: 40px 0 12px; }
        .magna-prose p { color: var(--text-muted); font-size: 16.5px; }

        .magna-prose h2 + p, .magna-prose h1 + p { margin-top: 16px; font-size: 17px; }
        .magna-prose ul, .magna-prose ol { padding-left: 22px; color: var(--text-muted); font-size: 16px; margin-top: 16px; }
        .magna-prose li + li { margin-top: 8px; }
        .magna-prose a { color: var(--accent); font-weight: 500; }
        .magna-prose a:hover { text-decoration: underline; }
        .magna-prose strong { color: var(--text-main); font-weight: 600; }
        .magna-prose code { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 0.92em; background: var(--bg-soft); padding: 0.15em 0.4em; border-radius: 5px; }

        /* The original's .prose: a long-form column, wider than a section
           head and with paragraph spacing of its own. It is the text block
           AFTER the head — the head keeps its own 680px measure, and the
           64px between them is the head's own bottom margin. */
        .prose .magna-column > .magna-block--text + .magna-block--text { margin-top: 64px; max-width: 780px; }
        .prose .magna-column > .magna-block--text + .magna-block--text > p,
        .prose .magna-column > .magna-block--text:first-child > p { font-size: 16.5px; margin-bottom: 20px; }
        .prose .magna-column > .magna-block--text:first-child { max-width: 780px; }
        .prose .magna-column > .magna-block--quote { max-width: 780px; }

        .center .magna-prose { margin-left: auto; margin-right: auto; text-align: center; }
        .center .magna-heading { text-align: center; }
        .center .magna-block--button { display: inline-flex; }

        /*
            The features block's baseline.

            A band restyles it — into cards, chips, rows, steps, layers —
            but an editor may drop one on any page, and a block with no
            rule at all would draw its icons at the SVG default of
            300x150. This is the floor everything else overrides.
        */
        .magna-features { display: grid; gap: 22px; }
        .magna-features--icon-grid { grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); }
        .magna-features--horizontal-list { grid-template-columns: 1fr; gap: 14px; }
        .magna-features--horizontal-list .magna-features__item { display: flex; gap: 14px; align-items: flex-start; }
        .magna-features__icon {
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 44px; height: 44px; border-radius: 12px; margin-bottom: 16px;
            background: var(--accent-light); color: var(--accent);
        }
        .magna-features--horizontal-list .magna-features__icon { margin-bottom: 0; }
        .magna-features__icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .magna-features__body { min-width: 0; }
        .magna-features__title { font-family: var(--serif); font-size: 18px; font-weight: 600; }
        .magna-features__description { color: var(--text-muted); font-size: 14.5px; margin-top: 6px; }

        /* reveal */
        .reveal { opacity: 0; transform: translateY(40px); transition: opacity 0.8s var(--ease), transform 0.8s var(--ease); }
        .reveal.in { opacity: 1; transform: translateY(0); }

        /* Two sections that read as one composition. */
        .head-continue { padding-bottom: 0; }
        .tail-continue { padding-top: 64px; }

        /* ===== PROBLEM ===== */
        .problem { background: var(--bg-white); border-bottom: 1px solid var(--border); }
        .problem-grid > .magna-section__inner > .magna-columns,
        .arch-grid > .magna-section__inner > .magna-columns,
        .builder-grid > .magna-section__inner > .magna-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 70px; align-items: start; }
        .arch-grid > .magna-section__inner > .magna-columns,
        .builder-grid > .magna-section__inner > .magna-columns { align-items: center; }
        .problem.split .magna-prose p { font-size: 16px; }
        .problem.split .magna-prose > p, .arch .magna-prose > p { margin-bottom: 18px; }
        .magna-quote__text {
            border-left: 3px solid var(--accent); background: var(--accent-light);
            border-radius: 0 var(--radius) var(--radius) 0; padding: 22px 26px;
            font-family: var(--serif); font-size: 19px; font-weight: 600; color: var(--text-main);
        }
        .magna-quote__attribution { margin-top: 10px; color: var(--text-light); font-size: 13.5px; }

        /* The "shift" list — a horizontal features list on a problem band. */
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list { display: grid; gap: 14px; }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item { display: flex; gap: 18px; padding: 20px; background: var(--bg-main); border: 1px solid var(--border); border-radius: var(--radius); transition: all 0.3s var(--ease); }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item:hover { border-color: var(--accent); transform: translateX(6px); box-shadow: var(--shadow-sm); }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon { width: 44px; height: 44px; flex-shrink: 0; background: var(--accent-light); color: var(--accent); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0; }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon svg { width: 22px; height: 22px; }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__title { font-size: 16px; margin-bottom: 3px; font-family: var(--sans); }
        .problem-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__description { font-size: 13.5px; color: var(--text-muted); margin: 0; }

        /* ===== WHY (cards) ===== */
        .why { background: var(--bg-main); }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; }
        .why.grid-2:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid { grid-template-columns: repeat(2, 1fr); }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item {
            position: relative; background: var(--bg-white); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 36px 32px; transition: all 0.45s var(--ease); overflow: hidden;
        }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item::before {
            content:""; position: absolute; inset: 0; z-index: 0; opacity: 0; transition: opacity 0.45s ease;
            background: linear-gradient(160deg, #fff 0%, var(--accent-light) 100%);
        }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item > * { position: relative; z-index: 1; }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item:hover { transform: translateY(-8px); border-color: rgba(99,102,241,0.4); box-shadow: var(--shadow-lg); }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item:hover::before { opacity: 1; }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon {
            width: 56px; height: 56px; background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; border-radius: 16px;
            display: flex; align-items: center; justify-content: center; margin-bottom: 24px;
            box-shadow: 0 12px 26px -8px var(--accent-glow); transition: transform 0.4s var(--ease);
        }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item:hover .magna-features__icon { transform: rotate(-8deg) scale(1.05); }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon svg { width: 26px; height: 26px; }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__title { font-family: var(--serif); font-size: 21px; margin-bottom: 10px; font-weight: 600; }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__description { color: var(--text-muted); font-size: 14.5px; }

        /* ===== ARCHITECTURE ===== */
        .arch { background: var(--bg-white); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .arch .magna-prose h2 { font-size: clamp(28px,3.6vw,42px); line-height: 1.65; }
        .arch .magna-column > .magna-block--text + .magna-block--text { margin-top: 20px; }
        .arch .magna-quote__text {
            font-family: var(--serif); font-size: 18px; font-weight: 600; color: var(--text-main);
            border-left: 3px solid var(--accent-2); padding: 0 0 0 20px; background: none;
            border-radius: 0; line-height: 1.5;
        }
        .arch .magna-block--quote { margin: 26px 0; }
        .arch .magna-column > .magna-block--quote + .magna-block--button { margin-top: 0; }
        .arch .magna-prose p { font-size: 15.5px; }
        .arch .magna-column:last-child .magna-features {
            background: var(--bg-dark); border-radius: var(--radius-lg); padding: 44px;
            box-shadow: var(--shadow-lg); position: relative; overflow: hidden;
            display: grid; gap: 10px;
        }
        .arch .magna-column:last-child .magna-features::before { content:""; position: absolute; inset: 0;
            background: radial-gradient(420px circle at 30% 20%, rgba(99,102,241,0.22), transparent 55%),
                        radial-gradient(380px circle at 80% 90%, rgba(79,172,254,0.14), transparent 55%); }
        .arch .magna-column:last-child .magna-features::after {
            content:""; order: -1; position: relative; z-index: 1; display: block;
            width: 150px; height: 150px; margin: 0 auto 12px;
            background: center / contain no-repeat var(--mark-svg);
            filter: drop-shadow(0 0 40px rgba(99,102,241,0.5));
        }
        .arch .magna-column:last-child .magna-features__item {
            position: relative; z-index: 1;
            border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.05); border-radius: 12px;
            padding: 12px 18px; display: flex; justify-content: space-between; align-items: center;
            font-size: 13px; color: #d6daec; font-weight: 500;
        }
        .arch .magna-column:last-child .magna-features__item:nth-child(3) { border-color: rgba(99,102,241,0.55); background: rgba(99,102,241,0.14); }
        .arch .magna-column:last-child .magna-features__body { flex: 1; }
        .arch .magna-column:last-child .magna-features__title { display: flex; align-items: center; justify-content: space-between; gap: 16px; font-size: 13px; font-weight: 500; font-family: var(--sans); letter-spacing: 0; color: #d6daec; }
        .arch .magna-column:last-child .magna-features__tag { font-size: 10.5px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent-3); flex-shrink: 0; }

        /* ===== HEADLESS ===== */
        .headless { background: var(--bg-main); }
        .headless-grid > .magna-section__inner > .magna-columns { display: grid; grid-template-columns: 0.95fr 1.05fr; gap: 64px; align-items: center; }
        .magna-code { border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-lg); border: 1px solid var(--border-dark); background: var(--bg-dark); width: 100%; }
        .magna-code__filename { display: flex; align-items: center; gap: 7px; padding: 13px 18px; background: rgba(255,255,255,0.04); border-bottom: 1px solid var(--border-dark); font-size: 12px; color: #676d8c; font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; }
        .magna-code__filename::before {
            content:""; width: 41px; height: 11px; flex-shrink: 0; margin-right: 3px;
            background: radial-gradient(circle at 5.5px 5.5px, #f87171 5.5px, transparent 5.5px),
                        radial-gradient(circle at 20.5px 5.5px, #fbbf24 5.5px, transparent 5.5px),
                        radial-gradient(circle at 35.5px 5.5px, #34d399 5.5px, transparent 5.5px);
        }
        .magna-code__pre { padding: 24px 26px; overflow-x: auto; font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 13px; line-height: 1.75; color: #c3c8de; }
        .magna-code__pre code { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 13px; line-height: 1.75; color: #c3c8de; white-space: pre; }

        /* The two "paths" cards beside the terminal. */
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list { display: grid; gap: 16px; margin: 28px 0 30px; }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item { display: flex; gap: 18px; padding: 24px; background: var(--bg-white); border: 1px solid var(--border); border-radius: var(--radius); transition: all 0.3s var(--ease); }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item:hover { border-color: var(--accent); transform: translateX(6px); box-shadow: var(--shadow-md); }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon { width: 48px; height: 48px; flex-shrink: 0; background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; border-radius: 13px; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 22px -8px var(--accent-glow); margin: 0; }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon svg { width: 23px; height: 23px; }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__title { font-size: 17px; margin-bottom: 4px; font-family: var(--sans); }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__description { font-size: 14px; color: var(--text-muted); margin: 0; }
        .headless-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__tag {
            display: inline-block; margin-left: 8px; font-size: 10.5px; font-weight: 700; letter-spacing: 0.07em;
            text-transform: uppercase; color: var(--accent); background: var(--accent-light); border-radius: 100px;
            padding: 3px 10px; vertical-align: middle;
        }
        .headless-grid .magna-block--text { margin-top: 28px; }
        .headless .magna-prose p { color: var(--text-muted); font-size: 15px; }

        /* ===== USE CASES ===== */
        .cases { background: var(--bg-dark); color: #fff; position: relative; overflow: hidden; }
        .cases::before { content:""; position: absolute; inset: 0; background: radial-gradient(700px circle at 80% 20%, rgba(139,92,246,0.14), transparent 50%), radial-gradient(600px circle at 10% 90%, rgba(99,102,241,0.12), transparent 50%); }
        .cases > .magna-section__inner { position: relative; }
        .cases .magna-prose h2, .cases .magna-prose h1 { color: #fff; }
        .cases .magna-prose h2 em { color: var(--accent-3); }
        .cases .magna-prose p { color: #c3c8de; }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .cases.grid-2 .magna-column > .magna-block--features.magna-features--icon-grid { grid-template-columns: repeat(2, 1fr); }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item {
            border: 1px solid var(--border-dark); background: rgba(255,255,255,0.04); border-radius: var(--radius);
            padding: 22px 20px; transition: all 0.35s var(--ease);
        }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item:hover { border-color: var(--accent); background: rgba(99,102,241,0.10); transform: translateY(-5px); }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__title { color: #fff; font-size: 15.5px; margin-bottom: 4px; font-family: var(--sans); }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__description { font-size: 12.5px; color: #9aa0b8; }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon { display: none; }
        .cases-foot .magna-column > .magna-block--container { margin-top: 44px; }
        .cases-foot .magna-container .magna-prose { max-width: none; }
        .cases-foot .magna-container .magna-prose p { font-family: var(--serif); font-size: 19px; font-weight: 600; color: #e8eaf6; }
        .cases-foot .magna-container .magna-block--button { margin-top: 0; }

        /* ===== PLUGINS ===== */
        .plugins { background: var(--bg-soft); overflow: hidden; }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; position: relative; }
        /*
            The connector, drawn when the band scrolls in: the line grows
            from the left and a dot runs its length once. Both hang off the
            features block, because the original's spare .plugin-line
            element is markup the document would otherwise have to carry.
        */
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid::before {
            content:""; position: absolute; top: 36px; left: 16%; right: 16%; height: 3px; border-radius: 3px;
            background: linear-gradient(90deg, var(--accent-3), var(--accent), var(--accent-2)); opacity: 0.85;
            transform-origin: left center;
            transition: transform 1.6s var(--ease) 0.15s;
        }
        .plugins .reveal .magna-column > .magna-block--features.magna-features--icon-grid::before { transform: scaleX(0); }
        .plugins .reveal.in .magna-column > .magna-block--features.magna-features--icon-grid::before { transform: scaleX(1); }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid::after {
            content:""; position: absolute; top: 36px; left: 16%; width: 16px; height: 16px; border-radius: 50%;
            background: var(--accent); transform: translate(-50%,-50%); margin-top: 1.5px;
            box-shadow: 0 0 0 7px rgba(99,102,241,0.16); opacity: 0; pointer-events: none;
        }
        .plugins .reveal.in .magna-column > .magna-block--features.magna-features--icon-grid::after {
            animation: travelDot 2.4s var(--ease) 0.4s forwards;
        }
        @keyframes travelDot { 0%{ left: 16%; opacity: 1; } 88%{ opacity: 1; } 100%{ left: 84%; opacity: 0; } }

        /* The step numbers pop in behind the line. */
        .plugins .reveal .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon { opacity: 0; }
        .plugins .reveal.in .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon {
            opacity: 1; animation: numPop 0.85s var(--ease) backwards;
        }
        .plugins .reveal.in .magna-features__item:nth-child(2) .magna-features__icon { animation-delay: 0.2s; }
        .plugins .reveal.in .magna-features__item:nth-child(3) .magna-features__icon { animation-delay: 0.4s; }
        @keyframes numPop {
            0%   { opacity: 0; transform: scale(0.2) translateY(16px); }
            55%  { opacity: 1; transform: scale(1.12) translateY(-2px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item { text-align: center; padding: 0 26px; position: relative; }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__icon {
            width: 72px; height: 72px; margin: 0 auto 24px; border-radius: 50%; background: var(--bg-white);
            border: 2px solid var(--accent); color: var(--accent); display: flex; align-items: center; justify-content: center;
            font-family: var(--serif); font-size: 25px; font-weight: 700; position: relative; z-index: 1;
            box-shadow: var(--shadow-sm);
            transition: transform 0.4s var(--ease), background 0.4s var(--ease), color 0.4s var(--ease), box-shadow 0.4s var(--ease);
        }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__item:hover .magna-features__icon { background: var(--accent); color: #fff; transform: translateY(-6px) scale(1.08); box-shadow: 0 18px 36px -10px var(--accent-glow); }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__title { font-size: 19px; margin-bottom: 10px; font-family: var(--sans); }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid .magna-features__description { font-size: 14px; color: var(--text-muted); }
        .plugins .magna-block--features + .magna-block--text { max-width: 720px; margin: 56px auto 0; text-align: center; }
        .plugins .magna-block--features + .magna-block--text .magna-prose p { font-size: 15px; }

        /* Plugin bands that hold rows rather than steps keep the row design. */


        /* ===== BUILDER ===== */
        .builder { background: var(--bg-white); border-top: 1px solid var(--border); }
        .builder-grid .magna-column > .magna-block--text + .magna-block--features,
        .builder-grid .magna-column > .magna-block--heading + .magna-block--features { margin-top: 26px; }
        .builder-grid .magna-column > .magna-block--features.magna-features--horizontal-list { display: grid; gap: 12px; margin-bottom: 32px; }
        .builder-grid .magna-column > .magna-block--features + .magna-block--button { margin-top: 0; }
        .builder-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__item { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; color: var(--text-main); font-weight: 500; }
        .builder-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon { width: 18px; height: 18px; background: none; color: var(--accent); border-radius: 0; margin: 3px 0 0; flex-shrink: 0; }
        .builder-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__icon svg { width: 18px; height: 18px; }
        .builder-grid .magna-column > .magna-block--features.magna-features--horizontal-list .magna-features__title { font-size: 15px; font-weight: 500; font-family: var(--sans); letter-spacing: 0; }

        /* ===== TWO AUDIENCES / CARD GRIDS ===== */
        .audiences { background: var(--bg-main); }
        .aud-grid .magna-column > .magna-block--container { display: grid; grid-template-columns: 1fr 1fr; gap: 26px; }
        .aud-grid .magna-column > .magna-block--container > .magna-block--container { display: block; transition: all 0.45s var(--ease); }
        .aud-grid .magna-column > .magna-block--container > .magna-block--container:hover { transform: translateY(-8px); border-color: rgba(99,102,241,0.4); box-shadow: var(--shadow-lg); }
        .aud-grid .magna-block--container .magna-heading h6 { background: none; border: 0; padding: 0; font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--accent); font-weight: 700; font-family: var(--sans); }
        .aud-grid .magna-block--container .magna-heading h6::before { display: none; }
        .aud-grid .magna-block--container .magna-block--heading { margin-bottom: 12px; }
        .aud-grid .magna-block--container h3 { font-family: var(--serif); font-size: 24px; font-weight: 600; margin: 0; }
        .aud-grid .magna-block--container .magna-block--heading + .magna-block--heading { margin-bottom: 14px; }
        .aud-grid .magna-block--container .magna-prose { max-width: none; }
        .aud-grid .magna-block--container .magna-prose p { color: var(--text-muted); font-size: 15px; }
        .aud-grid .magna-block--container .magna-prose > p { margin-bottom: 22px; }
        .aud-grid .magna-block--container .magna-block--text + .magna-block--features { margin-top: 22px; }
        .aud-grid .magna-block--container .magna-features--horizontal-list { display: grid; gap: 12px; border-top: 1px solid var(--border); padding-top: 22px; }
        .aud-grid .magna-block--container .magna-features--horizontal-list .magna-features__item { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-main); font-weight: 500; }
        .aud-grid .magna-block--container .magna-features--horizontal-list .magna-features__icon { width: 15px; height: 15px; background: none; color: var(--accent); border-radius: 0; margin: 0; flex-shrink: 0; }
        .aud-grid .magna-block--container .magna-features--horizontal-list .magna-features__icon svg { width: 15px; height: 15px; }
        .aud-grid .magna-block--container .magna-features--horizontal-list .magna-features__title { font-size: 14px; font-weight: 500; font-family: var(--sans); letter-spacing: 0; }

        /* ===== FAQ ===== */
        .faq { background: var(--bg-soft); }
        .magna-faq { max-width: 820px; margin: 0 auto; text-align: left; }
        .magna-faq__item { background: var(--bg-white); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 14px; overflow: hidden; transition: box-shadow 0.3s, border-color 0.3s; }
        .magna-faq__item[open] { border-color: var(--accent); box-shadow: var(--shadow-md); }
        .magna-faq__question { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 24px 28px; cursor: pointer; font-weight: 600; font-size: 17px; list-style: none; }
        .magna-faq__question::-webkit-details-marker { display: none; }
        .magna-faq__question::after {
            content: "+"; flex-shrink: 0; width: 32px; height: 32px; border-radius: 50%; background: var(--accent-light);
            color: var(--accent); display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 400; line-height: 1; transition: all 0.3s var(--ease);
        }
        .magna-faq__item[open] .magna-faq__question::after { background: var(--accent); color: #fff; transform: rotate(45deg); }
        .magna-faq__answer { padding: 0 28px 26px; color: var(--text-muted); font-size: 15px; }

        /* ===== CTA ===== */
        .cta { background: var(--bg-darker); color: #fff; text-align: center; padding: 120px 0; position: relative; overflow: hidden; }
        .cta::before { content:""; position: absolute; inset: 0; background: radial-gradient(600px circle at 50% 0%, rgba(99,102,241,0.26), transparent 55%); }
        /*
            The two drifting blobs. The original gives each its own element;
            a section has two pseudo-elements and its inner has more, which
            is enough to keep both blobs AND the glow without asking the
            document to carry decoration.
        */
        .cta::after, .cta > .magna-section__inner::before {
            content:""; position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.35; pointer-events: none;
        }
        .cta::after { width: 340px; height: 340px; background: var(--accent); top: -120px; left: -80px; animation: floaty 8s ease-in-out infinite; }
        .cta > .magna-section__inner::before {
            width: 300px; height: 300px; background: var(--accent-2); bottom: -120px; right: -60px;
            z-index: -1; animation: floaty 7s ease-in-out infinite reverse;
        }
        @keyframes floaty { 0%,100%{ transform: translateY(0); } 50%{ transform: translateY(-10px); } }
        .cta > .magna-section__inner { position: relative; z-index: 1; }
        .cta .magna-column { max-width: 636px; margin: 0 auto; }
        .cta .magna-prose { max-width: none; margin: 0 auto; text-align: center; }
        .cta .magna-prose h2 { font-family: var(--serif); font-size: clamp(34px,5vw,54px); line-height: 1.65; letter-spacing: -0.04em; color: #fff; font-weight: 700; }
        .cta .magna-prose h2 em { font-style: normal; font-weight: 800; color: var(--accent-3); }
        .cta .magna-prose h2 + p, .cta .magna-prose p { color: #c3c8de; font-size: 18px; }
        .cta .magna-block--heading { text-align: center; }
        .cta .magna-column > .magna-block--text + .magna-block--text { margin-top: 18px; }
        .cta .magna-block--text + .magna-block--button { margin-top: 38px; }
        .cta .magna-block--button + .magna-block--button { margin-left: 16px; }
        /* The quiet third link sits on its own line under the pair of
           buttons, as the original's .cta-tertiary does. */
        .cta .magna-block--button:has(.magna-btn--sm) { display: block; margin-left: 0; }
        .cta .magna-btn--sm { background: none; border: 0; backdrop-filter: none; padding: 0; font-size: 14px; color: #9aa0b8; transition: color 0.25s; }
        .cta .magna-btn--sm:hover { color: var(--accent-3); transform: none; box-shadow: none; }

        /* ===== SUBPAGE PATTERNS ===== */
        .feat-rows .magna-column > .magna-block--features { display: grid; gap: 14px; grid-template-columns: 1fr; }
        .feat-rows .magna-column > .magna-block--features .magna-features__item { display: flex; gap: 18px; align-items: flex-start; justify-content: space-between; padding: 22px 24px; background: var(--bg-white); border: 1px solid var(--border); border-radius: var(--radius); transition: all .3s var(--ease); }
        .feat-rows .magna-column > .magna-block--features .magna-features__item:hover { border-color: var(--accent); box-shadow: var(--shadow-sm); }
        .feat-rows .magna-column > .magna-block--features .magna-features__title { font-size: 16px; margin-bottom: 3px; font-family: var(--sans); }
        .feat-rows .magna-column > .magna-block--features .magna-features__description { font-size: 14px; color: var(--text-muted); margin: 0; }
        .feat-rows .magna-column > .magna-block--features .magna-features__icon { display: none; }
        .magna-column > .magna-block--features .magna-features__status { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; border-radius:100px; padding:4px 12px; white-space:nowrap; flex-shrink:0; margin-top:2px;
            background: var(--accent-light); color: var(--accent); border: 1px solid rgba(99,102,241,.18); }
        .magna-column > .magna-block--features .magna-features__status[data-tone="avail"] { background:#e7f8f1; color: var(--status-avail); border:1px solid rgba(13,150,104,.2); }
        .magna-column > .magna-block--features .magna-features__status[data-tone="dev"] { background:#fdf3e7; color: var(--status-dev); border:1px solid rgba(192,120,23,.2); }

        .cmp-wrap .magna-table { overflow-x:auto; border:1px solid var(--border); border-radius:var(--radius-lg); background:var(--bg-white); box-shadow:var(--shadow-sm); }
        .cmp-wrap .magna-table__table { width:100%; border-collapse:collapse; min-width:640px; font-size:14.5px; }
        .cmp-wrap .magna-table__table th, .cmp-wrap .magna-table__table td { padding:18px 22px; text-align:left; border-bottom:1px solid var(--border); vertical-align:top; }
        .cmp-wrap .magna-table__table thead th { background:var(--bg-soft); font-size:12.5px; letter-spacing:.05em; text-transform:uppercase; }
        .cmp-wrap .magna-table__table tbody tr:last-child td { border-bottom:none; }
        .cmp-wrap .magna-table__table td:first-child { font-weight:600; white-space:nowrap; }
        .cmp-wrap .magna-table__table td:last-child { font-weight:600; color:var(--text-main); }
        .cmp-wrap .magna-table__table td:not(:first-child):not(:last-child) { color: var(--text-muted); }

        /* Everything else a page builder can drop on a page. */
        .magna-callout { border-radius: var(--radius); padding: 20px 24px; border-left: 3px solid var(--accent); background: var(--accent-light); color: var(--text-muted); }
        .magna-callout__heading { font-weight: 700; color: var(--text-main); margin-bottom: 6px; }
        .magna-callout--success { border-left-color: var(--status-avail); }
        .magna-callout--warning { border-left-color: var(--status-dev); }
        .magna-callout--danger { border-left-color: #dc2626; }
        .magna-divider { border: 0; border-top: 1px solid var(--border); }
        .magna-divider--icon-center { display: flex; align-items: center; gap: 14px; }
        .magna-divider--icon-center .magna-divider__line { flex: 1; }
        .magna-image, .magna-image figure { margin: 0; }
        .magna-image img { border-radius: var(--radius-lg); }
        .magna-image figcaption { margin-top: 10px; color: var(--text-light); font-size: 13.5px; text-align: center; }
        .magna-gallery { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .magna-gallery img { border-radius: var(--radius); }
        .magna-spacer--xs { height: 8px; } .magna-spacer--sm { height: 18px; } .magna-spacer--md { height: 34px; }
        .magna-spacer--lg { height: 60px; } .magna-spacer--xl { height: 90px; } .magna-spacer--2xl { height: 130px; }
        .magna-stats { display: grid; gap: 26px; text-align: center; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
        .magna-stats__value { display: block; font-family: var(--serif); font-size: clamp(34px,4vw,48px); font-weight: 800; letter-spacing: -0.04em;
            background: linear-gradient(120deg, var(--accent), var(--accent-2)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .magna-stats__label { display: block; color: var(--text-muted); font-size: 14px; margin-top: 6px; }
        .magna-pricing { display: grid; gap: 26px; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); }
        .magna-pricing__tier { background: var(--bg-white); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 36px 32px; display: flex; flex-direction: column; gap: 14px; }
        .magna-pricing__tier--highlighted { border-color: var(--accent); box-shadow: var(--shadow-md); }
        .magna-pricing__amount { font-family: var(--serif); font-size: 42px; font-weight: 800; letter-spacing: -0.04em; }
        .magna-pricing__period { color: var(--text-light); font-size: 14px; }
        .magna-pricing__features { list-style: none; display: grid; gap: 10px; color: var(--text-muted); font-size: 14.5px; }
        .magna-testimonials { display: grid; gap: 26px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .magna-testimonials__item { background: var(--bg-white); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 32px 30px; }
        .magna-testimonials__quote { font-family: var(--serif); font-size: 17px; font-weight: 500; line-height: 1.55; }
        .magna-testimonials__author { margin-top: 16px; color: var(--text-light); font-size: 14px; }
        .magna-team { display: grid; gap: 26px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .magna-team__photo { border-radius: var(--radius-lg); margin-bottom: 14px; }
        .magna-team__role { color: var(--accent); font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
        .magna-team__bio { color: var(--text-muted); font-size: 14px; margin-top: 8px; }
        .magna-logos { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 34px; }
        .magna-logos--grayscale img { filter: grayscale(1); opacity: 0.6; transition: filter 0.3s, opacity 0.3s; }
        .magna-logos--grayscale .magna-logos__item:hover img { filter: none; opacity: 1; }
        .magna-logos img { max-height: 34px; width: auto; }
        .magna-icon { color: var(--accent); }
        .magna-icon__glyph { fill: none; stroke: currentColor; stroke-width: 1.6; }
        .magna-search { display: flex; gap: 10px; }
        .magna-search input { flex: 1; padding: 14px 18px; border-radius: 100px; border: 1px solid var(--border); background: var(--bg-white); color: inherit; font: inherit; font-size: 14px; }
        .magna-block--video iframe, .magna-block--video video, .magna-embed iframe { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: var(--radius-lg); }
        .magna-file { display: inline-flex; align-items: center; gap: 12px; padding: 14px 20px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg-white); }
        .magna-entries, .magna-loop { display: grid; gap: 26px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .magna-frontend-page { padding: 150px 0 90px; }
        .magna-frontend-page > * { max-width: var(--maxw); margin-inline: auto; padding-inline: 32px; }

        /* ===== FOOTER ===== */
        footer { background: var(--bg-darker); color: var(--text-light); padding: 90px 0 40px; font-size: 14px; border-top: 1px solid var(--border-dark); }
        footer .magna-section { padding: 0; background: none; border: 0; }
        footer .footer-main > .magna-section__inner > .magna-columns { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 48px; padding-bottom: 56px; border-bottom: 1px solid var(--border-dark); }
        footer .magna-logo__link { display: inline-flex; align-items: center; gap: 11px; }
        footer .magna-logo__text { font-family: var(--serif); font-size: 24px; font-weight: 700; color: #fff; letter-spacing: -0.04em; }
        footer .magna-block--logo { margin-bottom: 18px; }
        footer .magna-prose { max-width: 300px; }
        footer .magna-prose p { color: var(--text-light); font-size: 14px; margin-bottom: 22px; }
        footer .magna-heading h6 { background: none; border: 0; padding: 0; color: #fff; font-size: 14px; letter-spacing: 0.02em; text-transform: none; font-family: var(--sans); font-weight: 700; }
        footer .magna-heading h6::before { display: none; }
        footer .magna-block--heading { margin-bottom: 22px; }
        footer .magna-nav__list { list-style: none; display: grid; gap: 13px; }
        footer .magna-nav a { transition: color 0.2s, padding-left 0.2s; font-size: 14px; }
        footer .magna-nav a:hover { color: var(--accent-3); padding-left: 5px; }
        footer .footer-bottom { padding-top: 34px; font-size: 13px; }
        footer .footer-bottom .magna-prose { max-width: none; }
        footer .footer-bottom .magna-prose p { margin: 0; font-size: 13px; }
        footer a:hover { color: #fff; }
        .footer-fallback { padding-bottom: 34px; }

        /* ===== BACK TO TOP ===== */
        .to-top {
            position: fixed; bottom: 30px; right: 30px; z-index: 900; width: 52px; height: 52px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 12px 30px -6px var(--accent-glow); border: none;
            opacity: 0; visibility: hidden; transform: translateY(20px) scale(0.8); transition: all 0.4s var(--ease);
        }
        .to-top.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .to-top:hover { transform: translateY(-4px) scale(1.05); }
        .to-top svg { width: 20px; height: 20px; }

        /* ===== ACCESSIBILITY ===== */
        a:focus-visible, button:focus-visible, summary:focus-visible {
            outline: 3px solid var(--accent-3); outline-offset: 3px; border-radius: 6px;
        }
        .skip-link { position: absolute; left: -9999px; top: 0; z-index: 6000; background: var(--accent); color: #fff; padding: 12px 20px; border-radius: 0 0 10px 0; }
        .skip-link:focus { left: 0; }

        /* ===== VIEW TRANSITIONS (MPA) ===== */
        @view-transition { navigation: auto; }
        ::view-transition-old(root) { animation-duration: 0.18s; }
        ::view-transition-new(root) { animation-duration: 0.24s; }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
        .trust .magna-column > .magna-block--features, .cursor-glow, .running-line, .scroll-hint .mouse::before, 
            .magna-heading h6::before { animation: none !important; }
            .reveal,
            .hero .magna-column > .magna-block,
            .hero .magna-container { opacity: 1 !important; transform: none !important; transition: none !important; animation: none !important; }
            .plugins .reveal .magna-column > .magna-block--features.magna-features--icon-grid::before { transform: scaleX(1) !important; }
            .plugins .magna-column > .magna-block--features.magna-features--icon-grid::after { animation: none !important; }
            .cursor-glow { display: none; }
            ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }
        }

        /* ===== RESPONSIVE ===== */
        @media(max-width: 1024px) {
            .hero > .magna-section__inner > .magna-columns,
            .problem-grid > .magna-section__inner > .magna-columns,
            .arch-grid > .magna-section__inner > .magna-columns,
            .headless-grid > .magna-section__inner > .magna-columns,
            .builder-grid > .magna-section__inner > .magna-columns { grid-template-columns: 1fr; gap: 40px; }
        .why:not(.feat-rows) .magna-column > .magna-block--features.magna-features--icon-grid { grid-template-columns: repeat(2, 1fr); }
        .cases .magna-column > .magna-block--features.magna-features--icon-grid { grid-template-columns: repeat(2, 1fr); }
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid { grid-template-columns: 1fr; gap: 40px; }
        /* The connector is a desktop composition; its dot goes with it. */
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid::before,
        .plugins .magna-column > .magna-block--features.magna-features--icon-grid::after { display: none; }
            footer .footer-main > .magna-section__inner > .magna-columns { grid-template-columns: 1fr 1fr; }
            .scroll-hint { display: none; }
            .hero { padding-bottom: 70px; }
            .magna-columns { flex-direction: column; }
            .magna-column { flex: 1 1 100% !important; }
        }
        @media(max-width: 680px) {
            .container, .magna-section__inner { padding: 0 22px; }
            .magna-section { padding: 80px 0; }
            header nav, .nav-right { display: none; }
            .hamburger { display: flex; }
        .why:not(.feat-rows) .magna-column > .magna-block--features, 
        .cases .magna-column > .magna-block--features, 
        .cases.grid-2 .magna-column > .magna-block--features, 
            .aud-grid .magna-container,
            footer .footer-main > .magna-section__inner > .magna-columns { grid-template-columns: 1fr; }
            .hero .magna-container { padding: 28px !important; }
            .cursor-glow { display: none; }
        .feat-rows .magna-column > .magna-block--features .magna-features__item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .magna-block--button + .magna-block--button { margin-left: 0; }
        }
    </style>
</head>
<body>

<a class="skip-link" href="#main">Skip to content</a>

{{-- The wordmark, and the icons every block view draws by name. A document
     stores a NAME and never markup — the same rule the core icon registry
     enforces, kept here because a theme cannot register icons of its own. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    {{--
        The mark's chase animation lives HERE, inside the sprite, not in the
        page stylesheet — and that is not a preference.

        Every wordmark draws the mark through <use>, which clones the symbol
        into a shadow tree. WebKit does not apply the document's stylesheets
        to that tree for non-inherited properties, and `animation` is one, so
        a rule in <head> animates the symbol nobody looks at and leaves every
        clone still. A style element inside the referenced fragment reaches
        the clones on every engine. The reduced-motion guard has to live in
        here with it for exactly the same reason.
    --}}
    <style>
        .running-line { stroke-dasharray: 28 56; stroke-dashoffset: 84; animation: pulse-chase 2s cubic-bezier(0.25,1,0.5,1) infinite; }
        @keyframes pulse-chase {
            0%   { stroke-dasharray: 15 69; stroke-dashoffset: 84; }
            40%  { stroke-dasharray: 38 46; }
            100% { stroke-dasharray: 15 69; stroke-dashoffset: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .running-line { animation: none !important; }
        }
    </style>
    <defs>
        <linearGradient id="mg" x1="0" y1="0" x2="34" y2="34" gradientUnits="userSpaceOnUse">
            <stop stop-color="#6366f1"/>
            <stop offset="1" stop-color="#8b5cf6"/>
        </linearGradient>
        <linearGradient id="neon-glow" x1="0" y1="0" x2="34" y2="34" gradientUnits="userSpaceOnUse">
            <stop offset="0%" stop-color="#00f2fe"/>
            <stop offset="50%" stop-color="#4facfe"/>
            <stop offset="100%" stop-color="#f355da"/>
        </linearGradient>
        <symbol id="magna-mark" viewBox="0 0 34 34">
            <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mg)" stroke-width="2.4" fill="rgba(99,102,241,.06)"/>
            <path class="running-line" d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#neon-glow)" stroke-width="2.6" stroke-linecap="round" fill="none"/>
            <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mg)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
        <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/></symbol>
        <symbol id="i-code" viewBox="0 0 24 24"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></symbol>
        <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
        <symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></symbol>
        <symbol id="i-modules" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></symbol>
        <symbol id="i-screen" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
        <symbol id="i-terminal" viewBox="0 0 24 24"><path d="M4 17l6-6-6-6M12 19h8"/></symbol>
        <symbol id="i-type" viewBox="0 0 24 24"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></symbol>
        <symbol id="i-layout" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="8" height="7" rx="1"/><rect x="15" y="14" width="6" height="7" rx="1"/></symbol>
        <symbol id="i-layers" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5"/></symbol>
        <symbol id="i-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
        <symbol id="i-building" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></symbol>
        <symbol id="i-list" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
        <symbol id="i-devices" viewBox="0 0 24 24"><rect x="2" y="4" width="13" height="16" rx="2"/><rect x="17" y="8" width="5" height="12" rx="1.5"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M18 17l-3-3-4 4-5-5"/></symbol>
        <symbol id="i-users" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="i-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 5 15.4a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9.4a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 8.6 5H9a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V12a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
        <symbol id="i-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/></symbol>
        <symbol id="i-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
        <symbol id="i-arrow-up" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></symbol>
        <symbol id="i-close" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
    </defs>
</svg>

@php
    /*
        Where the wordmark points. The layout contract supplies it because
        "/" is not always the site's front door — an install whose admin
        panel is mounted at the root never lets the page router answer it.
        A theme older than that contract falls back to "/".
    */
    $home = $homeUrl ?? '/';

    /*
        The wordmark. The site's name is drawn the way the original drew it:
        the last word set apart as a small accent suffix when there is one
        ("Magna CMS"), the whole name otherwise — so a site called something
        else still gets a wordmark rather than a hardcoded one.
    */
    $brandParts = preg_split('/\s+/', trim($siteName)) ?: [$siteName];
    $brandSuffix = count($brandParts) > 1 ? array_pop($brandParts) : '';
    $brandName = implode(' ', $brandParts);
@endphp

{{--
    Visitor chrome, and only for a visitor. The builder canvas renders
    through this same layout — that is what keeps the canvas honest — so a
    splash screen over the page would be a splash screen over the thing
    being edited.
--}}
@unless($builderMode ?? false)
    <div class="preloader" data-preloader>
        <div class="pre-logo">
            <svg class="logo-mark"><use href="#magna-mark"/></svg>
            <span>{{ $brandName }}</span>
        </div>
        <div class="pre-bar"><i></i></div>
    </div>
    <div class="scroll-progress" data-progress></div>
    <div class="cursor-glow" data-cursor></div>
@endunless

@if(!empty($headerPartHtml))
    {{-- A site-designed header part replaces the theme's own chrome. --}}
    <header data-header>{!! $headerPartHtml !!}</header>
@else
    <header data-header>
        <div class="container nav-wrap">
            <a href="{{ $home }}" class="logo">
                <svg class="logo-mark"><use href="#magna-mark"/></svg>
                {{ $brandName }}@if($brandSuffix !== '')<span class="cms">{{ $brandSuffix }}</span>@endif
            </a>
            @if(!empty($headerMenu))
                <nav>
                    <ul>
                        @foreach($headerMenu as $item)
                            <li><a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url'] ?? null) }}"
                                   @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif>{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
            <div class="nav-right">
                <a href="#get-started" class="btn btn-primary">Get Started
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
            <button class="hamburger" type="button" aria-label="Menu" aria-expanded="false" data-burger><span></span><span></span><span></span></button>
        </div>
    </header>
@endif

@unless($builderMode ?? false)
    <div class="mobile-menu" data-menu aria-hidden="true">
        <div class="mm-top">
            <a href="{{ $home }}" class="logo">
                <svg class="logo-mark"><use href="#magna-mark"/></svg>
                {{ $brandName }}@if($brandSuffix !== '')<span class="cms">{{ $brandSuffix }}</span>@endif
            </a>
            <button class="mm-close" type="button" aria-label="Close menu" data-menu-close>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#i-close"/></svg>
            </button>
        </div>
        <nav class="mm-links" aria-label="Mobile" data-menu-links></nav>
        <div class="mm-foot">
            <a href="#get-started" class="btn btn-primary">Get Started
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            <div class="mm-note">Open source · Built to be built on</div>
        </div>
    </div>
@endunless

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
    <footer class="footer--custom">{!! $footerPartHtml !!}</footer>
@else
    <footer>
        <div class="container footer-fallback">
            <a href="{{ $home }}" class="logo">
                <svg class="logo-mark"><use href="#magna-mark"/></svg>
                {{ $brandName }}@if($brandSuffix !== '')<span class="cms">{{ $brandSuffix }}</span>@endif
            </a>
        </div>
        <div class="container"><span>&copy; {{ now()->year }} {{ $siteName }}</span></div>
    </footer>
@endif

@unless($builderMode ?? false)
    <button class="to-top" type="button" aria-label="Back to top" data-to-top>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><use href="#i-arrow-up"/></svg>
    </button>

    <script>
        (function () {
            var root = document.documentElement;
            try { if (window.sessionStorage.getItem('magnaSplash') === '1') { root.classList.add('no-splash'); } } catch (e) {}

            var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function ready(fn) {
                if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
            }

            ready(function () {
                var preloader = document.querySelector('[data-preloader]');
                if (preloader) {
                    window.setTimeout(function () {
                        preloader.classList.add('done');
                        try { window.sessionStorage.setItem('magnaSplash', '1'); } catch (e) {}
                    }, root.classList.contains('no-splash') ? 0 : 1300);
                }

                /*
                    The hero's entrance. Armed and fired from here rather
                    than left to CSS alone: the armed class is what hides
                    the blocks, so a visitor whose script never runs sees
                    the hero rather than an empty band. Skipped entirely
                    under reduced motion — arming it there would hide the
                    hero and then decline to bring it back.
                */
                var hero = document.querySelector('main .magna-section.hero');
                if (hero) {
                    /*
                        Set directly, NOT inside requestAnimationFrame: rAF
                        does not fire in a background tab, and a hero that
                        stays hidden until someone happens to focus the tab
                        is worse than one that animates a frame early. The
                        keyframes fill `both`, so starting from the hidden
                        state needs no separate frame anyway.
                    */
                    hero.classList.add('loaded');
                }

                var header = document.querySelector('[data-header]');
                var progress = document.querySelector('[data-progress]');
                var toTop = document.querySelector('[data-to-top]');

                function onScroll() {
                    var y = window.scrollY || window.pageYOffset;
                    if (header) { header.classList.toggle('scrolled', y > 40); }
                    if (toTop) { toTop.classList.toggle('show', y > 600); }
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

                var cursor = document.querySelector('[data-cursor]');
                if (cursor && !reduced && window.matchMedia('(pointer: fine)').matches) {
                    window.addEventListener('mousemove', function (event) {
                        cursor.style.opacity = '1';
                        cursor.style.transform = 'translate(' + event.clientX + 'px,' + event.clientY + 'px) translate(-50%,-50%)';
                    }, { passive: true });
                }

                /*
                    The drawer copies whatever navigation the header actually
                    has, so it keeps working when a site replaces the theme's
                    chrome with a header part of its own design.
                */
                var menu = document.querySelector('[data-menu]');
                var burger = document.querySelector('[data-burger]');
                var links = document.querySelector('[data-menu-links]');
                if (menu && links && header) {
                    Array.prototype.forEach.call(header.querySelectorAll('nav a'), function (source, index) {
                        var a = document.createElement('a');
                        a.href = source.getAttribute('href') || '#';
                        if (source.getAttribute('target')) { a.target = source.getAttribute('target'); a.rel = 'noopener noreferrer'; }
                        var idx = document.createElement('span');
                        idx.className = 'mm-idx';
                        idx.textContent = ('0' + (index + 1)).slice(-2);
                        a.appendChild(idx);
                        a.appendChild(document.createTextNode(source.textContent.trim()));
                        var arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        arrow.setAttribute('class', 'mm-arrow');
                        arrow.setAttribute('viewBox', '0 0 24 24');
                        arrow.setAttribute('fill', 'none');
                        arrow.setAttribute('stroke', 'currentColor');
                        arrow.setAttribute('stroke-width', '2');
                        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
                        use.setAttribute('href', '#i-arrow-right');
                        arrow.appendChild(use);
                        a.appendChild(arrow);
                        links.appendChild(a);
                    });

                    var setOpen = function (open) {
                        menu.classList.toggle('open', open);
                        menu.setAttribute('aria-hidden', open ? 'false' : 'true');
                        document.body.style.overflow = open ? 'hidden' : '';
                        if (burger) {
                            burger.classList.toggle('open', open);
                            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
                        }
                    };
                    if (burger) { burger.addEventListener('click', function () { setOpen(!menu.classList.contains('open')); }); }
                    var close = document.querySelector('[data-menu-close]');
                    if (close) { close.addEventListener('click', function () { setOpen(false); }); }
                    links.addEventListener('click', function (event) { if (event.target.closest('a')) { setOpen(false); } });
                    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { setOpen(false); } });
                }

                /*
                    The scroll hint belongs to a FULL-HEIGHT hero and to
                    nothing else, and the layout cannot know whether the
                    page has one — the document decides that. So it is
                    added here, beside the rest of the visitor chrome,
                    rather than smuggled into the page's content.
                */
                var fullHero = document.querySelector('main .magna-section.hero:not(.short)');
                if (fullHero) {
                    var hint = document.createElement('div');
                    hint.className = 'scroll-hint';
                    hint.setAttribute('aria-hidden', 'true');
                    var mouse = document.createElement('div');
                    mouse.className = 'mouse';
                    var word = document.createElement('span');
                    word.textContent = 'Scroll';
                    hint.appendChild(mouse);
                    hint.appendChild(word);
                    fullHero.appendChild(hint);
                }

                /* Reveal-on-scroll, applied to the blocks the renderer
                   emitted rather than to markup written for it. */
                if (!reduced && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function (entries) {
                        entries.forEach(function (entry) {
                            if (entry.isIntersecting) {
                                entry.target.classList.add('in');
                                observer.unobserve(entry.target);
                            }
                        });
                    }, { rootMargin: '0px 0px -60px 0px', threshold: 0.04 });

                    Array.prototype.forEach.call(
                        document.querySelectorAll('main .magna-section:not(.hero) > .magna-section__inner'),
                        function (target) { target.classList.add('reveal'); observer.observe(target); }
                    );
                }
            });
        })();
    </script>
@endunless

</body>
</html>

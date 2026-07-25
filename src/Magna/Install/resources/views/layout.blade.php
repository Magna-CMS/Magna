<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Install Magna CMS</title>
    <style>
        :root {
            --bg: #0b1020;
            --card: #121a30;
            --card-border: rgba(255, 255, 255, .08);
            --text: #e7ecf6;
            --muted: #94a3c0;
            --accent: #6366f1;
            --accent-2: #8b5cf6;
            --ok: #34d399;
            --warn: #fbbf24;
            --fail: #f87171;
            --input-bg: #0d1428;
            --input-border: #273252;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background:
                radial-gradient(1000px 500px at 80% -10%, rgba(99, 102, 241, .18), transparent 60%),
                radial-gradient(800px 400px at 10% 110%, rgba(139, 92, 246, .14), transparent 60%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 48px 20px;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: 100%; max-width: 620px; }
        .brand { display: flex; align-items: center; gap: 12px; justify-content: center; margin-bottom: 28px; }
        .brand svg { display: block; }
        .brand-name { font-size: 22px; font-weight: 700; letter-spacing: -.02em; }
        .brand-name span { color: var(--muted); font-weight: 500; }
        .steps { display: flex; gap: 6px; margin-bottom: 20px; }
        .steps li {
            list-style: none; flex: 1; text-align: center; font-size: 12px; color: var(--muted);
            padding-top: 10px; position: relative;
        }
        .steps li::before {
            content: ""; display: block; height: 4px; border-radius: 99px;
            background: var(--input-border); margin-bottom: 8px;
        }
        .steps li.done::before, .steps li.active::before {
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
        }
        .steps li.active { color: var(--text); font-weight: 600; }
        .card {
            background: var(--card); border: 1px solid var(--card-border);
            border-radius: 16px; padding: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
        }
        h1 { font-size: 22px; letter-spacing: -.02em; margin-bottom: 6px; }
        .lead { color: var(--muted); font-size: 14.5px; line-height: 1.55; margin-bottom: 24px; }
        .group-title {
            font-size: 12px; text-transform: uppercase; letter-spacing: .08em;
            color: var(--muted); margin: 22px 0 10px;
        }
        .checks { list-style: none; }
        .checks li {
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
            padding: 11px 4px; border-bottom: 1px solid rgba(255, 255, 255, .05);
        }
        .checks li:last-child { border-bottom: 0; }
        .check-label { font-size: 14.5px; }
        .check-help { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
        .pill {
            flex-shrink: 0; font-size: 11.5px; font-weight: 700; letter-spacing: .04em;
            padding: 4px 10px; border-radius: 99px;
        }
        .pill-ok { color: var(--ok); background: rgba(52, 211, 153, .12); }
        .pill-warn { color: var(--warn); background: rgba(251, 191, 36, .12); }
        .pill-fail { color: var(--fail); background: rgba(248, 113, 113, .12); }
        .field { margin-bottom: 18px; }
        label { display: block; font-size: 13.5px; font-weight: 600; margin-bottom: 7px; }
        .hint { font-weight: 400; color: var(--muted); font-size: 12.5px; margin-top: 6px; }
        input[type=text], input[type=email], input[type=password], input[type=url], input[type=number], select {
            width: 100%; padding: 11px 14px; font-size: 14.5px; color: var(--text);
            background: var(--input-bg); border: 1px solid var(--input-border); border-radius: 10px;
            outline: none; transition: border-color .15s;
        }
        input:focus, select:focus { border-color: var(--accent); }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; }
        .drivers { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .drivers input { display: none; }
        .drivers label {
            display: block; text-align: center; padding: 14px 6px; cursor: pointer;
            border: 1px solid var(--input-border); border-radius: 10px; font-size: 13.5px;
            background: var(--input-bg); transition: border-color .15s, background .15s;
        }
        .drivers small { display: block; color: var(--muted); font-weight: 400; font-size: 11px; margin-top: 4px; }
        .drivers input:checked + label { border-color: var(--accent); background: rgba(99, 102, 241, .12); }
        .checkbox { display: flex; align-items: flex-start; gap: 10px; }
        .checkbox input { width: 16px; height: 16px; margin-top: 2px; accent-color: var(--accent); }
        .checkbox label { font-weight: 500; margin: 0; }
        .btn {
            display: inline-block; width: 100%; text-align: center; cursor: pointer;
            padding: 13px 20px; font-size: 15px; font-weight: 700; color: #fff;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            border: 0; border-radius: 10px; text-decoration: none; margin-top: 8px;
            transition: opacity .15s, transform .05s;
        }
        .btn:hover { opacity: .92; }
        .btn:active { transform: translateY(1px); }
        .btn[disabled] { opacity: .35; cursor: not-allowed; }
        .btn-ghost {
            background: transparent; border: 1px solid var(--input-border); color: var(--muted); font-weight: 600;
        }
        .actions { display: flex; align-items: center; gap: 12px; margin-top: 20px; }
        /* Buttons on a shared row size to their label instead of stretching
           full-width; the primary action is pushed to the right. */
        .actions .btn { width: auto; margin-top: 0; padding-inline: 22px; }
        .actions .btn:not(.btn-ghost) { margin-left: auto; }
        .errors {
            background: rgba(248, 113, 113, .1); border: 1px solid rgba(248, 113, 113, .3);
            border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; font-size: 14px; color: #fecaca;
        }
        .errors ul { margin-left: 18px; }
        .success-icon { width: 64px; height: 64px; margin: 4px auto 20px; display: block; }
        .summary { list-style: none; margin: 18px 0; }
        .summary li {
            display: flex; justify-content: space-between; font-size: 14px;
            padding: 9px 2px; border-bottom: 1px solid rgba(255, 255, 255, .05);
        }
        .summary li span:first-child { color: var(--muted); }
        .summary li:last-child { border-bottom: 0; }
        code {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 13px;
            background: var(--input-bg); border: 1px solid var(--input-border);
            padding: 2px 7px; border-radius: 6px;
        }
        .foot { margin-top: 22px; text-align: center; font-size: 12.5px; color: var(--muted); }
        .foot a { color: var(--muted); }
        @media (max-width: 560px) {
            .card { padding: 24px 20px; }
            .drivers { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <svg width="34" height="34" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <defs>
                <linearGradient id="mg" x1="0" y1="0" x2="34" y2="34">
                    <stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/>
                </linearGradient>
            </defs>
            <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mg)" stroke-width="2.4" fill="rgba(99,102,241,.09)"/>
            <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mg)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
        <div class="brand-name">Magna <span>CMS</span></div>
    </div>

    @php($labels = ['Requirements', 'Site', 'Database', 'Admin'])
    <ul class="steps">
        @foreach ($labels as $i => $label)
            <li class="{{ ($step ?? 0) > $i + 1 ? 'done' : (($step ?? 0) === $i + 1 ? 'active' : '') }}">{{ $label }}</li>
        @endforeach
    </ul>

    <div class="card">
        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>

    <p class="foot">Magna CMS installer &middot; this page disables itself after installation</p>
</div>
@yield('scripts')
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #070b18; --bg2: #0b1224;
            --card: rgba(17, 24, 44, .72); --border: rgba(148, 163, 184, .14);
            --text: #e7ecf5; --muted: #93a0b8; --faint: #64748b;
            --accent: #818cf8; --accent2: #22d3ee;
            --input: rgba(2, 6, 23, .55); --input-border: rgba(148, 163, 184, .22);
            --danger: #fb7185; --ok: #34d399;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--text);
            background:
                radial-gradient(1100px 520px at 15% -10%, rgba(99,102,241,.18), transparent 60%),
                radial-gradient(900px 500px at 100% 110%, rgba(34,211,238,.12), transparent 55%),
                linear-gradient(160deg, var(--bg), var(--bg2));
            display: flex; align-items: center; justify-content: center;
            padding: 28px; min-height: 100%;
        }
        .card {
            width: 100%; max-width: 440px;
            background: var(--card); border: 1px solid var(--border);
            border-radius: 22px; padding: 34px 32px;
            box-shadow: 0 30px 80px -30px rgba(0,0,0,.7), inset 0 1px 0 rgba(255,255,255,.04);
            backdrop-filter: blur(14px);
        }
        .brand { display: flex; align-items: center; gap: 11px; justify-content: center; margin-bottom: 22px; }
        .brand-logo {
            width: 40px; height: 40px; border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #22d3ee); padding: 1.5px;
            box-shadow: 0 8px 24px -8px rgba(99,102,241,.6);
        }
        .brand-logo > div { width: 100%; height: 100%; border-radius: 10.5px; background: #0b1224; display: flex; align-items: center; justify-content: center; }
        .brand-name { font-size: 18px; font-weight: 800; letter-spacing: -.02em; }
        h1 { font-size: 21px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 6px; text-align: center; }
        .lead { color: var(--muted); font-size: 14px; line-height: 1.55; text-align: center; margin: 0 auto 22px; max-width: 34ch; }
        form { margin: 0; }
        label { display: block; font-size: 12.5px; font-weight: 600; color: var(--muted); margin: 0 0 7px; }
        input[type=text], input[type=password] {
            width: 100%; padding: 12px 14px; font-size: 15px; color: var(--text);
            background: var(--input); border: 1px solid var(--input-border); border-radius: 11px;
            outline: none; transition: border-color .15s, box-shadow .15s; font-variant-numeric: tabular-nums;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(129,140,248,.16); }
        .otp { letter-spacing: .5em; text-align: center; font-size: 20px; font-weight: 700; padding-left: .5em; }
        .field { margin-bottom: 16px; }
        .btn {
            display: block; width: 100%; text-align: center; cursor: pointer; margin-top: 4px;
            padding: 13px 18px; font-size: 15px; font-weight: 700; color: #fff; border: 0; border-radius: 12px;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            box-shadow: 0 10px 26px -12px rgba(129,140,248,.8); transition: transform .05s, opacity .15s;
        }
        .btn:hover { opacity: .93; } .btn:active { transform: translateY(1px); }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 6px; width: auto; margin: 0; padding: 9px 4px;
            background: none; border: 0; box-shadow: none; color: var(--muted); font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-ghost:hover { color: var(--text); }
        summary.btn-ghost { list-style: none; margin-bottom: 4px; }
        summary.btn-ghost::-webkit-details-marker { display: none; }
        .alert { background: rgba(251,113,133,.1); border: 1px solid rgba(251,113,133,.3); color: #fecdd3; font-size: 13px; padding: 10px 12px; border-radius: 10px; margin-bottom: 16px; }
        .hint { color: var(--faint); font-size: 12.5px; line-height: 1.5; text-align: center; margin: 14px 0 0; }
        .keybox {
            margin: 4px 0 0; padding: 10px 12px; background: var(--input); border: 1px dashed var(--input-border);
            border-radius: 10px; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 13px;
            color: var(--text); text-align: center; letter-spacing: .12em; word-break: break-all;
        }
        .qr { display: flex; justify-content: center; margin: 4px 0 18px; }
        .qr svg, .qr img { width: 190px; height: 190px; border-radius: 14px; background: #fff; padding: 10px; box-shadow: 0 12px 30px -12px rgba(0,0,0,.6); }
        .divider { display: flex; align-items: center; gap: 12px; color: var(--faint); font-size: 12px; margin: 18px 0; }
        .divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: var(--border); }
        .row-between { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 18px; }
        .codes { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .codes li { font-family: ui-monospace, Menlo, monospace; font-size: 14px; letter-spacing: .06em; background: var(--input); border: 1px solid var(--input-border); border-radius: 9px; padding: 9px 10px; text-align: center; color: var(--text); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <div class="brand-logo"><div>
                <svg width="24" height="24" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <defs><linearGradient id="mlg" x1="0" y1="0" x2="34" y2="34"><stop stop-color="#a5b4fc"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>
                    <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mlg)" stroke-width="2.4" fill="rgba(129,140,248,.10)"/>
                    <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mlg)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
            </div></div>
            <span class="brand-name">{{ config('app.name') }}</span>
        </div>

        @yield('content')
    </main>
</body>
</html>

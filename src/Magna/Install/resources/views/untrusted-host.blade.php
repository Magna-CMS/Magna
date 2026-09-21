{{--
    Shown when TrustHosts refuses the Host header.

    Standalone rather than extending the installer layout: this renders from a
    global-stack failure, before the web group's ShareErrorsFromSession has
    bound $errors, and the layout dereferences it unconditionally.

    $host is client-supplied by definition, so every echo of it stays escaped.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Unrecognised address</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: #0b1020;
            color: #e7ecf6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 20px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 620px;
            background: #121a30; border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 16px; padding: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
        }
        h1 { font-size: 21px; letter-spacing: -.02em; margin-bottom: 10px; }
        p { color: #94a3c0; font-size: 14.5px; line-height: 1.6; margin-bottom: 16px; }
        dl { margin: 0 0 20px; font-size: 14px; }
        dt { color: #94a3c0; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
        dd { margin-bottom: 14px; }
        code {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 13px;
            background: #0d1428; border: 1px solid #273252;
            padding: 2px 7px; border-radius: 6px; word-break: break-all;
        }
        .fix { border-top: 1px solid rgba(255, 255, 255, .07); padding-top: 20px; }
        ol { margin-left: 20px; color: #94a3c0; font-size: 14.5px; line-height: 1.7; }
        li { margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="card">
    <h1>This address is not one this site answers to</h1>
    <p>
        The request arrived for a hostname the site has not been told to trust, so
        it was refused before any page was built. Magna checks this because it
        builds links &mdash; password-reset emails among them &mdash; from the
        address a request claims to arrive at.
    </p>

    <dl>
        <dt>Requested</dt>
        <dd><code>{{ $host !== '' ? $host : '(no Host header)' }}</code></dd>
        <dt>Configured as this site&rsquo;s address</dt>
        <dd><code>{{ $configuredUrl !== '' ? $configuredUrl : '(not set)' }}</code></dd>
    </dl>

    <div class="fix">
        <p><strong>If you are the site owner</strong>, pick whichever matches your intent:</p>
        <ol>
            <li>
                Moving the site to this address permanently &mdash; set
                <code>APP_URL</code> in <code>.env</code> to it. Subdomains of
                that host are trusted automatically.
            </li>
            <li>
                Serving one site at several addresses &mdash; list the extra ones,
                comma-separated, in <code>MAGNA_TRUSTED_HOSTS</code> in
                <code>.env</code>.
            </li>
            <li>
                Behind a proxy or load balancer &mdash; make sure it forwards the
                original <code>Host</code>, and that the proxy is listed in
                <code>config/trustedproxy.php</code>.
            </li>
        </ol>
        <p style="margin-top:16px;margin-bottom:0">Clear any config cache afterwards.</p>
    </div>
</div>
</body>
</html>

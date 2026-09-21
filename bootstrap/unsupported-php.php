<?php

// The page shown when the server's PHP is older than Magna supports.
//
// Included from public/index.php BEFORE vendor/autoload.php, on an interpreter
// that is by definition too old — so it must stay parseable by PHP 5: no
// strict-types declaration, no type declarations, no short closures, no match
// expressions, no attributes. Nothing here may be autoloaded or call a
// framework helper. A guardrail test pins that list.

$magnaRunning = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PHP version not supported</title>
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
        dl { margin: 0 0 20px; font-size: 14px; display: grid; grid-template-columns: auto auto; gap: 8px 18px; justify-content: start; }
        dt { color: #94a3c0; }
        code {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 13px;
            background: #0d1428; border: 1px solid #273252;
            padding: 2px 7px; border-radius: 6px;
        }
        .fix { border-top: 1px solid rgba(255, 255, 255, .07); padding-top: 20px; }
        ul { margin-left: 20px; color: #94a3c0; font-size: 14.5px; line-height: 1.7; }
        li { margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="card">
    <h1>This server&rsquo;s PHP is too old for Magna</h1>
    <p>Nothing is wrong with your download &mdash; the files are fine. Magna is
        built on Laravel, which sets the minimum PHP version, and this server is
        below it.</p>

    <dl>
        <dt>Required</dt>
        <dd><code>PHP 8.3.0</code> or newer</dd>
        <dt>Running</dt>
        <dd><code><?php echo $magnaRunning; ?></code></dd>
    </dl>

    <div class="fix">
        <p><strong>How to fix it</strong> &mdash; raise the PHP version, then reload this page:</p>
        <ul>
            <li>cPanel: <em>MultiPHP Manager</em>, pick this domain, choose PHP 8.3 or newer.</li>
            <li>Plesk: <em>Websites &amp; Domains</em> &rarr; <em>PHP Settings</em>.</li>
            <li>CloudPanel / RunCloud / Ploi: the site&rsquo;s <em>Settings</em> or <em>PHP</em> tab.</li>
            <li>XAMPP / MAMP: these bundle one PHP version &mdash; install a build whose version number is 8.3 or higher.</li>
            <li>Your own server: install the newer PHP-FPM package and point the site&rsquo;s pool at it.</li>
        </ul>
        <p style="margin-top:16px;margin-bottom:0">If your host offers no PHP 8.3, Magna cannot run there.</p>
    </div>
</div>
</body>
</html>

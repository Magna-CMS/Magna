<?php

declare(strict_types=1);

/**
 * The root forwarder must strip trailing slashes itself, before it forwards.
 *
 * Laravel's stock public/.htaccess redirects "/erp/" by capturing
 * %{REQUEST_URI} — which, by the time that per-directory rule runs on a
 * forwarder layout, is the internally rewritten "/public/erp/". The shipped
 * site then answered every trailing-slash URL with a 301 into /public/…,
 * leaking the internal layout as the browser URL (seen live as
 * /erp/ -> /public/erp on installed sites). Only a redirect issued at the
 * root, while REQUEST_URI is still the client's, produces the right target.
 *
 * The forwarder is a heredoc inside bin/build-release.php, so this pins the
 * two properties an Apache run would need: the strip rule exists, and it runs
 * before the forwarding rewrite.
 */
it('strips trailing slashes before forwarding into public/', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    $strip = strpos($script, 'RewriteRule ^(.+)/$ /$1 [R=301,L]');
    $forward = strpos($script, 'RewriteRule ^(.*)$ public/$1 [L]');

    expect($strip)->not->toBeFalse('The forwarder no longer strips trailing slashes.')
        ->and($forward)->not->toBeFalse('The forwarder no longer forwards into public/.')
        ->and($strip)->toBeLessThan($forward);
});

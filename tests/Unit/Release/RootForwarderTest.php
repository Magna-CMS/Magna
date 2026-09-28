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

/**
 * The strip rule above is half of a loop unless mod_dir is silenced.
 *
 * A forwarder layout leaves the application's own directories at the web
 * root, and some of them share a name with a route — themes/ on disk next to
 * the /themes admin page. mod_dir answers the directory request /themes with
 * a 301 to /themes/, the strip rule answers /themes/ with a 301 to /themes,
 * and the page is unreachable (seen live as ERR_TOO_MANY_REDIRECTS). Neither
 * rule is wrong on its own, so the fix belongs where the two meet: the
 * forwarder serves nothing from disk, so DirectorySlash has no job.
 */
it('disables the mod_dir slash fixup that would fight the strip rule', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    $off = strpos($script, 'DirectorySlash Off');
    $strip = strpos($script, 'RewriteRule ^(.+)/$ /$1 [R=301,L]');

    expect($off)->not->toBeFalse('The forwarder no longer disables DirectorySlash, so a route named after a shipped directory loops.')
        ->and($strip)->not->toBeFalse('The forwarder no longer strips trailing slashes.');
});

/**
 * Both halves of the forwarder must be in the archive it verifies, or the
 * fix ships in the builder and never reaches a site.
 */
it('ships the forwarder it wrote', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    expect($script)->toContain("file_put_contents(\$stage.'/.htaccess', root_htaccess());")
        ->and($script)->toContain("'index.php', '.htaccess', 'artisan', '.env.example',");
});

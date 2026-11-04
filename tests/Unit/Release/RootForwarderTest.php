<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

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
 * The forwarder as the builder writes it, cut out of the heredoc, because
 * requiring bin/build-release.php would run a release.
 */
function forwarderHeredoc(string $label): string
{
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    preg_match("/<<<'{$label}'\n(.*?)\n{$label};/s", $script, $heredoc);

    return $heredoc[1] ?? throw new RuntimeException("No {$label} heredoc in the builder.");
}

/**
 * Where Apache sends a URL: walks the shipped rules in order, with
 * mod_rewrite's per-directory semantics, and answers `forbidden`, `redirect`
 * or the rewritten path. A directive it does not understand fails the test,
 * so a new rule cannot slip past this walk unread.
 */
function apacheAnswer(string $uri): string
{
    $conditionsHold = true;

    foreach (explode("\n", forwarderHeredoc('HT')) as $line) {
        $line = trim($line);

        if (! str_starts_with($line, 'Rewrite') || $line === 'RewriteEngine On') {
            continue;
        }

        if (preg_match('/^RewriteCond %\{REQUEST_URI\} !(\S+)$/', $line, $condition) === 1) {
            $conditionsHold = $conditionsHold && preg_match('~'.$condition[1].'~', $uri) !== 1;

            continue;
        }

        if (preg_match('/^RewriteRule (\S+) (\S+) \[(\S+)\]$/', $line, $rule) !== 1) {
            throw new RuntimeException("Unread directive: {$line}");
        }

        [, $pattern, $target, $flags] = $rule;
        $applies = $conditionsHold;
        $conditionsHold = true;

        if (! $applies || preg_match('~'.$pattern.'~', ltrim($uri, '/')) !== 1) {
            continue;
        }

        return match (true) {
            str_contains($flags, 'F') => 'forbidden',
            str_contains($flags, 'R=') => 'redirect',
            default => (string) preg_replace('~'.$pattern.'~', $target, ltrim($uri, '/')),
        };
    }

    throw new RuntimeException("Nothing answered {$uri}.");
}

/** The same walk for IIS: URL Rewrite matches case-insensitively and the first stopProcessing rule wins. */
function iisAnswer(string $uri): string
{
    $config = simplexml_load_string(forwarderHeredoc('XML'));

    foreach ($config->{'system.webServer'}->rewrite->rules->rule as $rule) {
        if (preg_match('~'.$rule->match['url'].'~i', ltrim($uri, '/'), $groups) !== 1) {
            continue;
        }

        return match ((string) $rule->action['type']) {
            'CustomResponse' => 'forbidden',
            'Rewrite' => str_replace('{R:1}', $groups[1], (string) $rule->action['url']),
            default => throw new RuntimeException('Unread action: '.$rule->action['type']),
        };
    }

    throw new RuntimeException("Nothing answered {$uri}.");
}

/**
 * An archive-root install on disk with a real public/storage link, so the walk
 * above ends at a real file or at nothing.
 *
 * @param  Closure(string): void  $proof  receives the install's root
 */
function withForwardedInstall(Closure $proof): void
{
    $root = sys_get_temp_dir().'/magna-forwarder-'.bin2hex(random_bytes(6));
    $disk = new Filesystem;

    foreach (['app/public/media/share.png', 'logs/laravel.log', 'framework/sessions/abc', 'app/private/invoice.pdf', 'app/backups/site.zip'] as $file) {
        $disk->ensureDirectoryExists(dirname($root.'/storage/'.$file));
        file_put_contents($root.'/storage/'.$file, $file);
    }

    $disk->ensureDirectoryExists($root.'/public');
    $disk->link($root.'/storage/app/public', $root.'/public/storage');

    try {
        $proof($root);
    } finally {
        // The link first, so deleting the tree cannot follow a Windows junction.
        is_link($root.'/public/storage') ? @unlink($root.'/public/storage') : @rmdir($root.'/public/storage');
        $disk->deleteDirectory($root);
    }
}

/**
 * Media URLs are /storage/media/…, and sites served from the archive root
 * answered every one of them 403: the deny list named storage, ahead of the
 * forward that would have reached public/storage. Seen live on 2026-11-04
 * with the first uploads on lekha.jrstudios.dev.
 */
it('lets a public file through to public/storage', function (string $answer): void {
    withForwardedInstall(function (string $root) use ($answer): void {
        expect(realpath($root.'/'.$answer('/storage/media/share.png')))
            ->toBe(realpath($root.'/storage/app/public/media/share.png'));
    });
})->with(['apache' => 'apacheAnswer', 'iis' => 'iisAnswer']);

/**
 * What makes taking the deny off storage safe. The forward is the boundary:
 * every URL lands under public/, so inside storage/app/public, and the private
 * tree sits beside that, not under it. No file there means the front
 * controller, where Laravel answers 404 (PrivateStorageUrlTest proves that half).
 */
it('never serves private storage', function (string $answer, string $uri): void {
    withForwardedInstall(function (string $root) use ($answer, $uri): void {
        $target = $answer($uri);

        expect($target)->toStartWith('public/storage/')
            ->and(file_exists($root.'/'.$target))->toBeFalse();
    });
})->with(['apache' => 'apacheAnswer', 'iis' => 'iisAnswer'])->with([
    '/storage/logs/laravel.log',
    '/storage/framework/sessions/abc',
    '/storage/app/private/invoice.pdf',
    '/storage/app/backups/site.zip',
]);

it('still denies the application\'s own directories', function (string $answer, string $uri): void {
    expect($answer($uri))->toBe('forbidden');
})->with(['apache' => 'apacheAnswer', 'iis' => 'iisAnswer'])
    ->with(['/app/Models/User.php', '/config/app.php', '/src/Magna/MagnaServiceProvider.php', '/vendor/autoload.php', '/.env', '/.git/config']);

/**
 * Both halves of the forwarder must be in the archive it verifies, or the
 * fix ships in the builder and never reaches a site.
 */
it('ships the forwarder it wrote', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    expect($script)->toContain("file_put_contents(\$stage.'/.htaccess', root_htaccess());")
        ->and($script)->toContain("'index.php', '.htaccess', 'artisan', '.env.example',");
});

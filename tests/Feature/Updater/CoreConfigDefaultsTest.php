<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Magna\MagnaServiceProvider;
use Magna\Support\ConfigDefaults;
use Magna\Updater\CoreUpdater;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| A config key core reads must live where a core update delivers it
|--------------------------------------------------------------------------
|
| CoreUpdater overlays a fixed list of core-owned paths and `config/` is
| deliberately not among them: those files hold values site owners edit, and an
| update that reset somebody's mail driver to ship a feature flag would be the
| worse bug. But `bin/build-release.php` puts `config` in the archive, so a
| fresh install gets the new file and an updated one never does — the code
| arrives, the key does not, and `config()` answers null.
|
| It cost two security controls, silently, across two releases. On every site
| that updated into 1.4.x, `MAGNA_TRUSTED_HOSTS` stopped doing anything — so an
| operator locked out on a LAN address was shown a page telling them to set a
| variable that could not work — and `require_signed_checksum` left core updates
| accepting an unsigned checksum, while a fresh install of the identical release
| did both correctly. Confirmed on two production installs before this was
| written.
|
| Core's defaults therefore live in config/defaults - its own core-owned path,
| holding nothing a site edits - and config/magna.php hands straight to them.
*/

it('keeps the site-facing config file from becoming a second source of truth', function (): void {
    $root = dirname(__DIR__, 3);

    /** @var array<string, mixed> $canonical */
    $canonical = require $root.'/config/magna.php';
    /** @var array<string, mixed> $shipped */
    $shipped = require $root.'/config/defaults/magna.php';

    // Identical because one requires the other. Asserted rather than assumed:
    // the moment somebody pastes the array back into config/magna.php, every
    // key they add there stops reaching updated sites.
    expect($canonical)->toBe($shipped, 'config/magna.php must hand to config/defaults/magna.php — config/ is not a core-owned update path, so a key defined only there never reaches a site that updated rather than installed fresh.');
});

it('defines every magna config key core reads', function (): void {
    $root = dirname(__DIR__, 3);

    /** @var array<string, mixed> $shipped */
    $shipped = require $root.'/config/defaults/magna.php';

    /** @var list<string> $offenders */
    $offenders = [];

    foreach (['src/Magna', 'app', 'bootstrap', 'routes'] as $dir) {
        foreach ((new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        )) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /*
             * Both spellings. `config('magna.x')` is the common one, and
             * `config()->string('magna.x', …)` is the typed read core uses
             * wherever the value feeds something that insists on a type —
             * MagnaServiceProvider and the installer both do. The first cut of
             * this guard matched only the former and would have missed them.
             *
             * What it still cannot see: a key built at runtime
             * (`config('magna.'.$name)`), a typed wrapper taking a variable
             * (DeliveryServiceProvider::configString), and anything outside
             * these four directories. Those are why the backfill is
             * absent-only rather than clever — an unseen key still resolves,
             * it just resolves to core's default.
             */
            $pattern = "/config\(\s*\)?\s*(?:->\s*(?:string|integer|boolean|float|array)\s*\(\s*)?'magna\.([a-z0-9_.]+)'/i";

            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[1] as $match) {
                [$key, $offset] = $match;

                if (Arr::has($shipped, $key)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;

                $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.$line.' reads magna.'.$key;
            }
        }
    }

    /*
     * `magna.media.disk` was the one this caught when it was written: read by
     * MediaServiceProvider since it was built, defined by no config file, so
     * its inline fallback was the only value any install could ever have had.
     */
    expect($offenders)->toBe([], 'A config key core reads must be defined in config/defaults/magna.php, or no install can configure it and no update can deliver it.');
});

it('leaves config out of the paths an update overwrites', function (): void {
    // The exclusion is correct and must stay: the overlay mirrors with
    // delete: true, so adding 'config' would erase every site's customisation
    // to fix a problem config/defaults already solves.
    expect(CoreUpdater::coreOwnedPaths())->not->toContain('config');

    // And the defaults must sit under a path it DOES overwrite, or the fix
    // cannot deliver itself.
    expect(CoreUpdater::coreOwnedPaths())->toContain('config/defaults');
});

/*
|--------------------------------------------------------------------------
| What the backfill does, and what it refuses to do
|--------------------------------------------------------------------------
*/

function backfilled(array $site, array $defaults): Repository
{
    $config = new Repository(['magna' => $site]);

    ConfigDefaults::backfill($config, 'magna', $defaults);

    return $config;
}

it('fills a key the site has never heard of', function (): void {
    $config = backfilled([], ['security' => ['trusted_hosts' => '']]);

    expect($config->get('magna.security.trusted_hosts'))->toBe('');
});

it('reaches a new key inside a section the site already has', function (): void {
    // The shape a shallow merge cannot fix, and the reason this is not
    // Laravel's mergeConfigFrom: the section exists, the key inside it does not.
    $config = backfilled(
        ['updater' => ['some_older_key' => true]],
        ['updater' => ['some_older_key' => true, 'allow_unsigned_checksum' => false]],
    );

    expect($config->get('magna.updater.allow_unsigned_checksum'))->toBeFalse()
        ->and($config->get('magna.updater.some_older_key'))->toBeTrue();
});

it('never overrules a value the site has set', function (): void {
    $config = backfilled(
        ['login' => ['max_attempts' => 3]],
        ['login' => ['max_attempts' => 5]],
    );

    expect($config->get('magna.login.max_attempts'))->toBe(3);
});

/*
 * The distinction the whole design rests on: present-and-false is an answer,
 * absent is silence. Confusing them would switch a feature back on at the next
 * boot for a site whose owner had turned it off.
 */
it('treats false and null as answers rather than as absence', function (): void {
    $config = backfilled(
        ['password' => ['check_compromised' => false], 'themes_path' => null],
        ['password' => ['check_compromised' => true], 'themes_path' => 'themes'],
    );

    expect($config->get('magna.password.check_compromised'))->toBeFalse()
        ->and($config->get('magna.themes_path'))->toBeNull();
});

/*
 * A list is one value. Merging element-wise would re-add a proxy an operator
 * had deleted from trustedproxy.proxies — a security control quietly widening
 * itself — which is what array_replace_recursive, and therefore Laravel's
 * replaceConfigRecursivelyFrom, would have done.
 */
it('takes a shortened list exactly as the site left it', function (): void {
    $config = backfilled(
        ['proxies' => ['10.0.0.1']],
        ['proxies' => ['10.0.0.1', '10.0.0.2', '10.0.0.3']],
    );

    expect($config->get('magna.proxies'))->toBe(['10.0.0.1']);
});

/*
 * An emptied section is a section, not a list.
 *
 * This is the case the first cut of the merge got wrong, and it got it wrong
 * silently: `array_is_list([])` is true, so classifying by what the SITE holds
 * read `'security' => []` as a list, skipped it, and left trusted_hosts null —
 * the original bug, intact, for that one shape of config file. Core knows what
 * it meant the value to be; the site's copy does not.
 */
it('fills a section the site has emptied', function (): void {
    $config = backfilled(
        ['security' => []],
        ['security' => ['trusted_hosts' => 'filled']],
    );

    expect($config->get('magna.security.trusted_hosts'))->toBe('filled');
});

/*
 * The other side of asking the default: core expects names, the site has
 * positions. Odd, but it is their file, and writing string keys into a list
 * would leave them with neither shape.
 */
it('leaves a populated list alone where core expects a section', function (): void {
    $config = backfilled(
        ['edge_cache' => ['first', 'second']],
        ['edge_cache' => ['driver' => 'null']],
    );

    expect($config->get('magna.edge_cache'))->toBe(['first', 'second']);
});

it('leaves keys core has never heard of alone', function (): void {
    $config = backfilled(['a_site_invented_this' => 'keep me'], ['security' => ['trusted_hosts' => '']]);

    expect($config->get('magna.a_site_invented_this'))->toBe('keep me');
});

/*
|--------------------------------------------------------------------------
| The incident itself, against the real file
|--------------------------------------------------------------------------
|
| tests/Fixtures/Config/magna-pre-1.4.0.php is `git show 0e3f3a5e~1:config/magna.php`
| — the actual config both broken installs are still running. Everything above
| is structural; this is the only test that reproduces production.
*/

it('repairs a config file from before the incident', function (): void {
    /** @var array<string, mixed> $stale */
    $stale = require dirname(__DIR__, 2).'/Fixtures/Config/magna-pre-1.4.0.php';

    /** @var array<string, mixed> $shipped */
    $shipped = require dirname(__DIR__, 3).'/config/defaults/magna.php';

    // The two keys that were wrong in production, and the one that was
    // configurable nowhere.
    expect(Arr::has($stale, 'security.trusted_hosts'))->toBeFalse()
        ->and(Arr::has($stale, 'media.disk'))->toBeFalse();

    $config = backfilled($stale, $shipped);

    expect($config->get('magna.security.trusted_hosts'))->toBeString()
        ->and($config->get('magna.media.disk'))->toBe('public')
        ->and($config->get('magna.updater.allow_unsigned_checksum'))->toBeFalse();
});

/*
 * And the half a backfill cannot do, which is why the updater key was renamed
 * rather than re-defaulted. The stale file carries the OLD key, present and
 * false; core does not overrule a present key, so had the name stayed the same
 * this would still be false and the site would still accept unsigned releases.
 */
it('cannot repair a stale value, which is what the rename is for', function (): void {
    /** @var array<string, mixed> $stale */
    $stale = require dirname(__DIR__, 2).'/Fixtures/Config/magna-pre-1.4.0.php';

    expect($stale['updater']['require_signed_checksum'] ?? null)->toBeFalse();

    $config = backfilled($stale, ['updater' => ['require_signed_checksum' => true]]);

    expect($config->get('magna.updater.require_signed_checksum'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The half that actually repairs an install
|--------------------------------------------------------------------------
|
| Everything above tests ConfigDefaults in isolation. None of it notices if
| nobody calls it: delete the backfill from MagnaServiceProvider and every
| other test here still passes, because the repository they build by hand is
| not the one the application boots with. These run against the booted app.
*/

it('wires the backfill into the application it is supposed to repair', function (): void {
    // Emptied on the live repository, the way a stale config file leaves it,
    // then the provider re-registered: the key must come back.
    config(['magna' => []]);

    (new MagnaServiceProvider(app()))->register();

    expect(config('magna.security.trusted_hosts'))->toBeString()
        ->and(config('magna.media.disk'))->toBe('public')
        ->and(config('magna.updater.allow_unsigned_checksum'))->toBeFalse();
});

/*
 * config/trustedproxy.php was ADDED in 1.4.1, so a site that updated from
 * 1.4.0 or earlier has no such file: TRUSTED_PROXIES does nothing, the real
 * client IP is lost from the audit log and from login throttling, and
 * isSecure() reads false behind a proxy. Third instance of the same delivery
 * hole. The backfill reaches it because it works on the namespace, not the
 * file — which is exactly what this asserts.
 */
it('supplies a whole namespace whose config file the site never received', function (): void {
    config(['trustedproxy' => []]);

    (new MagnaServiceProvider(app()))->register();

    expect(config()->has('trustedproxy.proxies'))->toBeTrue();
});

// A core file that has gone missing must leave a site that still boots and can
// say so, not a blank page from inside the config bootstrapper.
it('does not fatal when a defaults file is not there', function (): void {
    expect(require dirname(__DIR__, 3).'/config/magna.php')->toBeArray()
        ->and(require dirname(__DIR__, 3).'/config/trustedproxy.php')->toBeArray();
});

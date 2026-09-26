<?php

declare(strict_types=1);

namespace Magna\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Supplies config keys a site's own config file has never heard of.
 *
 * A one-click update overwrites `src/Magna`, `app`, `bootstrap`, `routes` and
 * a short list of build outputs — the list in CoreUpdater::CORE_OWNED_PATHS.
 * It deliberately does not overwrite `config/`, because those files hold values
 * site owners edit, and an update that reset somebody's mail driver to ship a
 * new feature flag would be worse than the feature being missing.
 *
 * The consequence went unnoticed for two releases: a release that adds a config
 * key works perfectly on a fresh install — `bin/build-release.php` puts
 * `config` in the archive — and does nothing at all on an updated one, where
 * the code arrives and the key does not. It fails silently, because a missing
 * key reads as null and every caller has a sensible-looking fallback. Two
 * security controls shipped that way and were inert on every updated site:
 * `magna.security.trusted_hosts`, which left the host allowlist empty, and
 * `magna.updater.require_signed_checksum`, which left core updates accepting an
 * unsigned checksum.
 *
 * So core's own defaults live in `src/Magna/Config/defaults` — inside the one
 * path every core updater ever shipped has overlaid — and are backfilled here.
 * The site's file stays in charge of everything it defines.
 *
 * Neither Laravel helper does this job:
 *
 *   - `mergeConfigFrom()` is `array_merge`, one level deep. The site's
 *     `magna.updater` section already existed, so a shallow merge would have
 *     kept it whole and never reached the new key inside it — which is the
 *     exact shape of the bug.
 *   - `replaceConfigRecursivelyFrom()` recurses, but through
 *     `array_replace_recursive`, which merges lists element-wise: a site that
 *     trimmed `trustedproxy.proxies` down to one entry would silently get the
 *     defaults' remaining entries appended back onto it.
 *   - Both skip entirely when the config cache is warm. `config:cache` clears
 *     the cache and boots a fresh application to read it, so a backfill run
 *     unconditionally is baked into the cache file like any other value — and
 *     the one case their guard would catch, a cache written before the update,
 *     is the stale install this exists for.
 *
 * What it cannot do, and deliberately: a key the site's file DEFINES is the
 * site's, whatever it says. That is why the signed-checksum regression needed a
 * renamed key rather than a new default — the stale file had the old key
 * present and set to false, which is indistinguishable from an operator who
 * turned it off.
 */
final class ConfigDefaults
{
    /**
     * Fills in what is missing under one config key, and nothing else.
     *
     * @param  array<string, mixed>  $defaults  core's own values, as shipped
     */
    public static function backfill(Repository $config, string $key, array $defaults): void
    {
        $existing = $config->get($key);

        $config->set($key, self::fill(is_array($existing) ? $existing : [], $defaults));
    }

    /**
     * @param  array<array-key, mixed>  $existing
     * @param  array<array-key, mixed>  $defaults
     * @return array<array-key, mixed>
     */
    private static function fill(array $existing, array $defaults): array
    {
        foreach ($defaults as $name => $default) {
            /*
             * Present wins, whatever it says.
             *
             * `false`, `null` and `''` are answers. A site that turned a
             * feature off, or blanked a key it does not use, has said something
             * — and a backfill that reads those as "unset" would switch the
             * feature back on at the next boot, quietly, on a site whose owner
             * had decided otherwise. Only a key that is genuinely absent is
             * filled, which is the one case where the site has said nothing
             * because it was never asked.
             */
            if (array_key_exists($name, $existing)) {
                if (self::isSection($default) && self::acceptsSection($existing[$name])) {
                    // A section, so look inside it: this is what reaches a new
                    // key added under a section the site's file already has.
                    $existing[$name] = self::fill($existing[$name], $default);
                }

                continue;
            }

            $existing[$name] = $default;
        }

        return $existing;
    }

    /**
     * Whether core means this value to hold named settings.
     *
     * Asked of the DEFAULT, never of the site's copy, and that is the whole
     * subtlety. `array_is_list([])` is true, so classifying by what the site
     * happens to have meant a section they had emptied — `'security' => []` —
     * read as a list and was skipped, leaving `trusted_hosts` null: the
     * original bug, still there, for that one shape of file. Core knows what it
     * intended the value to be; the site's copy does not.
     *
     * @param  mixed  $default
     *
     * @phpstan-assert-if-true array<array-key, mixed> $default
     */
    private static function isSection($default): bool
    {
        return is_array($default) && $default !== [] && ! array_is_list($default);
    }

    /**
     * Whether the site's value can take one.
     *
     * An empty array can: it is a section with nothing in it yet. A populated
     * list cannot — core expects names and the site has positions, which is
     * odd, but it is their file and writing string keys into it would leave
     * them with neither. Theirs stands.
     *
     * @param  mixed  $existing
     *
     * @phpstan-assert-if-true array<array-key, mixed> $existing
     */
    private static function acceptsSection($existing): bool
    {
        return is_array($existing) && ($existing === [] || ! array_is_list($existing));
    }
}

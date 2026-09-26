<?php

declare(strict_types=1);

namespace Magna\Support;

use Illuminate\Support\Arr;
use Magna\MagnaServiceProvider;

/**
 * How far a site's own config/magna.php has drifted from core's defaults.
 *
 * Nothing here is repaired, on purpose: a key the site's file defines is the
 * site's, whatever it says (ConfigDefaults). What an operator needs is to
 * SEE the drift — which keys their file lacks and is quietly being handed
 * from core, and which keys it still sets that core has since replaced with
 * a differently named one, so their setting is no longer read at all.
 *
 * Computed on demand for System Info and `magna:core:status --config`;
 * there is no ledger, because the backfill is stateless and idempotent and
 * a record of it would add state without adding correctness.
 */
final class ConfigDrift
{
    /**
     * Keys renamed so a changed default could reach updated sites (the only
     * way a present key's meaning can change — see ConfigDefaults): old dotted
     * key => the key that replaced it. A site still setting the old one has a
     * setting nothing reads.
     *
     * @var array<string, string>
     */
    public const TOMBSTONES = [
        'magna.updater.require_signed_checksum' => 'magna.updater.allow_unsigned_checksum',
    ];

    public function __construct(private readonly string $basePath) {}

    /**
     * @return array{site_file_is_forwarder: bool, missing: list<string>, tombstones_set: list<string>}
     */
    public function report(): array
    {
        $canonical = $this->load(MagnaServiceProvider::coreDefaultsPath('magna'));
        $site = $this->load($this->basePath.'/config/magna.php');

        $missing = [];

        foreach (self::leafKeys($canonical) as $key) {
            if (! Arr::has($site, $key)) {
                $missing[] = 'magna.'.$key;
            }
        }

        $tombstonesSet = [];

        foreach (array_keys(self::TOMBSTONES) as $old) {
            $key = substr($old, strlen('magna.'));

            if (Arr::has($site, $key) && Arr::get($site, $key) !== null) {
                $tombstonesSet[] = $old;
            }
        }

        return [
            'site_file_is_forwarder' => $site === $canonical,
            'missing' => $missing,
            'tombstones_set' => $tombstonesSet,
        ];
    }

    /** @return array<array-key, mixed> */
    private function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }

    /**
     * Every dotted path to a value that is not itself a section — a list
     * counts as one value, as it does for the backfill.
     *
     * @param  array<array-key, mixed>  $tree
     * @return list<string>
     */
    private static function leafKeys(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix.'.'.$name;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $keys = [...$keys, ...self::leafKeys($value, $key)];
            } else {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}

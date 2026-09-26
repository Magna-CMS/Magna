<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Composer\Semver\Semver;
use Magna\Plugins\Exceptions\PluginCompatibilityException;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\PluginDisableResult;
use Throwable;

/**
 * Which plugins say they cannot run here, and what to do about it.
 *
 * The manifest's `compat` block is the contract: `compat.magna` is the core
 * range the plugin was written for, `compat.php` the PHP it needs. A plugin
 * that declares `^1.0` has said it will not run on 2.0, and a core update
 * must not make it try; one that declares `^8.4` has said the same of a PHP
 * 8.3 host. `compat.php` was parsed since the manifest existed and enforced
 * nowhere until now.
 *
 * One answer serves the update pre-flight, the admin's modal, the enable
 * path, the finalizer's post-update notice and System Info.
 */
final class PluginCompatibilityCheck
{
    public function __construct(private readonly PluginManager $plugins) {}

    /**
     * Refuse a plugin whose manifest rules out this core or this PHP.
     *
     * @throws PluginCompatibilityException
     */
    public static function assertCompatible(Manifest $manifest, string $coreVersion): void
    {
        if (! $manifest->isCompatibleWith($coreVersion)) {
            throw new PluginCompatibilityException(
                "Plugin [{$manifest->name}] requires magna {$manifest->magnaCompat} but the installed core is {$coreVersion}."
            );
        }

        if (self::phpIncompatible($manifest)) {
            throw new PluginCompatibilityException(
                "Plugin [{$manifest->name}] requires PHP {$manifest->phpCompat} but this server runs PHP ".self::phpVersion().'.'
            );
        }
    }

    /**
     * Whether the plugin rules out the running PHP. A constraint that will
     * not parse is not held against the plugin: the check exists to stop a
     * known mismatch, not to refuse on a typo.
     */
    public static function phpIncompatible(Manifest $manifest, ?string $phpVersion = null): bool
    {
        try {
            return ! Semver::satisfies($phpVersion ?? self::phpVersion(), $manifest->phpCompat);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Enabled plugins whose compat range does not satisfy $coreVersion.
     *
     * @return list<IncompatiblePlugin>
     */
    public function incompatibleWithCore(string $coreVersion): array
    {
        $enabledNames = PluginRecord::query()->where('enabled', true)->pluck('name')->all();

        if ($enabledNames === []) {
            return [];
        }

        $incompatible = [];

        foreach ($this->plugins->discover() as $info) {
            /** @var PluginInfo $info */
            if (in_array($info->manifest->name, $enabledNames, true) && ! $info->manifest->isCompatibleWith($coreVersion)) {
                $incompatible[] = new IncompatiblePlugin(
                    name: $info->manifest->name,
                    displayName: $info->manifest->displayName,
                    installedVersion: $info->manifest->version,
                    requiredCompat: $info->manifest->magnaCompat,
                );
            }
        }

        return $incompatible;
    }

    /**
     * Disable each, keeping data and settings; a plugin that will not
     * disable is named so the admin does it by hand.
     *
     * @param  list<IncompatiblePlugin>  $incompatible
     */
    public function disable(array $incompatible): PluginDisableResult
    {
        return $this->disableNamed(
            array_map(static fn (IncompatiblePlugin $p): string => $p->name, $incompatible),
            array_combine(
                array_map(static fn (IncompatiblePlugin $p): string => $p->name, $incompatible),
                array_map(static fn (IncompatiblePlugin $p): string => $p->displayName, $incompatible),
            ),
        );
    }

    /**
     * The same, by plugin name — what a journal can carry across processes.
     *
     * @param  list<string>  $names
     * @param  array<string, string>  $displayNames  name => label, for the message; the name itself otherwise
     */
    public function disableNamed(array $names, array $displayNames = []): PluginDisableResult
    {
        $disabled = [];
        $failed = [];

        foreach ($names as $name) {
            $label = $displayNames[$name] ?? $name;

            try {
                $this->plugins->disable($name);
                $disabled[] = $label;
            } catch (Throwable) {
                $failed[] = $label;
            }
        }

        return new PluginDisableResult($disabled, $failed);
    }

    /** The running PHP as a clean x.y.z, without a distribution's suffix. */
    private static function phpVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
    }
}

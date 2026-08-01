<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Magna\Plugins\Exceptions\InvalidManifestException;

/**
 * Validates a plugin's `name` before anything builds a filesystem path from it.
 *
 * A manifest's name is attacker-controlled on exactly one path — the Core
 * Plugin Manager's zip upload — and it is used to derive directories:
 * PluginZipInstaller explodes it into plugins-dev/{vendor}/{package}, and
 * PluginSource joins it onto the app root to find a bundled source. A name of
 * "../../etc/x" therefore escapes the application root and writes wherever the
 * web user can, so the shape has to be checked before it reaches any path
 * concatenation, not merely trusted because the uploader is an admin.
 *
 * The pattern is Composer's own package-name rule, which every real plugin
 * already satisfies (its composer.json could not resolve otherwise).
 */
final class PluginPackageName
{
    private const PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * @throws InvalidManifestException
     */
    public static function assertValid(string $name): void
    {
        if (! self::isValid($name)) {
            throw new InvalidManifestException(
                "\"{$name}\" is not a valid plugin package name (expected vendor/package, lowercase)."
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Magna\Updater\Engine;

/**
 * Whether a site's vendor/ can simply become the archive's.
 *
 * A release archive carries the complete vendor/ it was built and tested
 * with, and every site that installed from an archive got exactly that.
 * The one thing that makes a site's vendor/ its own is a package the
 * Marketplace installed into it with `composer require` — a package the
 * release's composer.json knows nothing about, which replacing vendor/
 * whole would silently drop. So the questions this answers are exactly
 * those: does the site already hold what the archive holds; if not, does
 * its composer.json ask for anything the release's does not; and at which
 * versions, so Composer can be asked to put them back after the switch.
 *
 * Pure file reading; the decisions are the engine's, made through
 * EngineContext.
 */
final class VendorPolicy
{
    public function __construct(
        private readonly string $sitePath,
        private readonly string $archivePath,
    ) {}

    /**
     * True when every package the archive was built with is already on the
     * site at the same version — nothing to replace. An archive that ships
     * no vendor/ at all matches trivially.
     */
    public function siteMatchesArchive(): bool
    {
        $archive = $this->installedPackages($this->archivePath);

        if ($archive === null) {
            return true;
        }

        $site = $this->installedPackages($this->sitePath);

        if ($site === null) {
            return false;
        }

        foreach ($archive as $name => $version) {
            if (($site[$name] ?? null) !== $version) {
                return false;
            }
        }

        return true;
    }

    /**
     * Packages the site's composer.json requires that the archive's does not,
     * with the version each is installed at — what `composer require` has to
     * put back once the release's vendor/ is in.
     *
     * @return array<string, string> name => version, '*' when the site has no record of it
     */
    public function foreignPackages(): array
    {
        $site = $this->composerJson($this->sitePath);
        $archive = $this->composerJson($this->archivePath);

        if ($site === null) {
            return [];
        }

        $archiveRequire = is_array($archive['require'] ?? null) ? array_keys($archive['require']) : [];
        $installed = $this->installedPackages($this->sitePath) ?? [];
        $foreign = [];

        foreach (is_array($site['require'] ?? null) ? array_keys($site['require']) : [] as $package) {
            if (! is_string($package) || $package === 'php' || str_starts_with($package, 'ext-') || str_starts_with($package, 'lib-')) {
                continue;
            }

            if (! in_array($package, $archiveRequire, true)) {
                $foreign[$package] = $installed[$package] ?? '*';
            }
        }

        return $foreign;
    }

    /**
     * Repositories the site's composer.json names that the archive's does
     * not. A foreign package may only be resolvable through one of these,
     * and carrying a repository we do not understand is not something an
     * update should do on its own.
     *
     * A `path` repository with no directory behind it is not one of them:
     * archives built before 1.3.25 shipped the build machine's absolute
     * path to the SDK, every site installed from one still carries it, and
     * it serves nothing. It leaves with the site's composer.json.
     *
     * @return list<string>
     */
    public function foreignRepositories(): array
    {
        $site = $this->composerJson($this->sitePath);
        $archive = $this->composerJson($this->archivePath);

        if ($site === null) {
            return [];
        }

        $archiveRepositories = array_map(self::repositoryKey(...), is_array($archive['repositories'] ?? null) ? $archive['repositories'] : []);
        $foreign = [];

        foreach (is_array($site['repositories'] ?? null) ? $site['repositories'] : [] as $repository) {
            $key = self::repositoryKey($repository);

            if ($key === null || in_array($key, $archiveRepositories, true) || $this->isDeadPathRepository($repository)) {
                continue;
            }

            $foreign[] = $key;
        }

        return $foreign;
    }

    /**
     * The foreign repositories an update CAN carry across: `path` repositories
     * whose url is relative and resolves to a directory inside the site — the
     * exact shape the Core Plugin Manager writes for a proprietary plugin
     * (`plugins-dev/<vendor>/<package>`). The directory travels with the site,
     * not with vendor/, so re-naming it in the release's composer.json after
     * the swap reproduces the site's wiring faithfully. Anything else — vcs,
     * composer, an absolute path onto some other machine's disk — stays
     * foreign and keeps meaning refusal.
     *
     * Keyed by the same `type:url` key foreignRepositories() answers with, so
     * the engine can tell whether EVERY foreign repository is portable.
     *
     * @return array<string, array{type: string, url: string}>
     */
    public function portableForeignRepositories(): array
    {
        $site = $this->composerJson($this->sitePath);
        $archive = $this->composerJson($this->archivePath);

        if ($site === null) {
            return [];
        }

        $archiveRepositories = array_map(self::repositoryKey(...), is_array($archive['repositories'] ?? null) ? $archive['repositories'] : []);
        $portable = [];

        foreach (is_array($site['repositories'] ?? null) ? $site['repositories'] : [] as $repository) {
            $key = self::repositoryKey($repository);

            if ($key === null || in_array($key, $archiveRepositories, true) || $this->isDeadPathRepository($repository)) {
                continue;
            }

            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path' || ! is_string($repository['url'] ?? null)) {
                continue;
            }

            $url = str_replace('\\', '/', $repository['url']);

            // Absolute paths and parent-escapes are someone else's disk, not
            // the site's own tree; they are not portable.
            if (preg_match('#^(/|[A-Za-z]:/)#', $url) === 1 || str_contains($url, '..')) {
                continue;
            }

            $real = realpath(rtrim($this->sitePath, '/\\').'/'.$url);
            $root = realpath($this->sitePath);

            if ($real === false || $root === false) {
                continue;
            }

            $real = str_replace('\\', '/', $real);
            $root = str_replace('\\', '/', $root);

            if ($real !== $root && ! str_starts_with($real, $root.'/')) {
                continue;
            }

            $portable[$key] = ['type' => 'path', 'url' => $url];
        }

        return $portable;
    }

    /**
     * The constraint the site's composer.json states for each foreign package
     * — what `composer require` should ask for once the repositories that
     * serve them are carried across. installed.json's version is the wrong
     * thing to pin a path-repository package to (Composer records a branch
     * alias or whatever the directory claimed), while the stated constraint
     * (`@dev`, the Core Plugin Manager's own wiring) reproduces the site's
     * composer.json as it was.
     *
     * @return array<string, string> name => constraint, '*' when the site states none readable
     */
    public function foreignRequireConstraints(): array
    {
        $site = $this->composerJson($this->sitePath);
        $require = is_array($site['require'] ?? null) ? $site['require'] : [];
        $constraints = [];

        foreach (array_keys($this->foreignPackages()) as $package) {
            $stated = $require[$package] ?? null;
            $constraints[$package] = is_string($stated) && $stated !== '' ? $stated : '*';
        }

        return $constraints;
    }

    private function isDeadPathRepository(mixed $repository): bool
    {
        if (! is_array($repository) || ($repository['type'] ?? null) !== 'path' || ! is_string($repository['url'] ?? null)) {
            return false;
        }

        $url = str_replace('\\', '/', $repository['url']);
        $absolute = preg_match('#^(/|[A-Za-z]:/)#', $url) === 1;

        return ! is_dir($absolute ? $url : rtrim($this->sitePath, '/\\').'/'.$url);
    }

    /**
     * name => version for every package Composer recorded as installed under
     * $root, or null when there is no record to read.
     *
     * @return array<string, string>|null
     */
    private function installedPackages(string $root): ?array
    {
        $file = rtrim($root, '/\\').'/vendor/composer/installed.json';

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 was the bare list.
        $packages = is_array($decoded) && is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;

        if (! is_array($packages)) {
            return null;
        }

        $versions = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $version = $package['version'] ?? null;

            if (is_string($version)) {
                $versions[$package['name']] = $version;
            }
        }

        return $versions;
    }

    /** @return array<array-key, mixed>|null */
    private function composerJson(string $root): ?array
    {
        $file = rtrim($root, '/\\').'/composer.json';

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function repositoryKey(mixed $repository): ?string
    {
        if (! is_array($repository)) {
            return null;
        }

        $type = is_string($repository['type'] ?? null) ? $repository['type'] : '?';
        $url = is_string($repository['url'] ?? null) ? str_replace('\\', '/', $repository['url']) : '?';

        return $type.':'.$url;
    }
}

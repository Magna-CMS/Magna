<?php

declare(strict_types=1);

namespace Magna\Marketplace;

/**
 * Makes a site's composer.json usable again before Composer is asked to run.
 *
 * A release built on a developer machine used to ship that machine's path
 * repositories — `{"type":"path","url":"C:/Users/.../magna-plugin-sdk"}` — plus
 * the `@dev` constraints they satisfied. On the customer's server that
 * directory does not exist, so every Composer command died with
 * "The `url` supplied for the path (C:/Users/...) repository does not exist"
 * and no plugin could be installed or updated at all. The builder no longer
 * writes those (see bin/support/release-composer.php), but installs created by
 * an earlier build still carry them, and nothing on the server would ever fix
 * itself.
 *
 * Deliberately narrow: only a `path` repository whose directory is genuinely
 * absent is removed, and only a `@dev`/`dev-*` constraint — the kind that
 * cannot resolve from Packagist once its path repository is gone — is relaxed.
 * A working path repository is left alone: the proprietary plugins (Marketplace,
 * Core Plugin Manager) are wired that way on purpose and must survive this.
 */
final class ComposerManifestRepair
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Repair the manifest in place, and describe what was changed.
     *
     * @return list<string> Empty when nothing needed repairing — the normal case.
     */
    public function repair(): array
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.'composer.json';

        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }

        /** @var mixed $manifest */
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return [];
        }

        $repositories = $manifest['repositories'] ?? null;
        if (! is_array($repositories)) {
            return [];
        }

        $notes = [];
        $wasList = array_is_list($repositories);

        foreach ($repositories as $key => $repository) {
            if (! is_array($repository) || ! $this->isMissingPathRepository($repository)) {
                continue;
            }

            unset($repositories[$key]);

            /** @var string $url */
            $url = $repository['url'];
            $notes[] = "Removed path repository \"{$url}\" — that directory does not exist on this server.";
        }

        if ($notes === []) {
            return [];
        }

        if ($repositories === []) {
            unset($manifest['repositories']);
        } else {
            $manifest['repositories'] = $wasList ? array_values($repositories) : $repositories;
        }

        // Whatever the surviving path repositories still provide keeps its
        // `@dev` constraint: those are the proprietary plugins (Marketplace,
        // Core Plugin Manager), they are not on Packagist, and rewriting them
        // to a public constraint would uninstall the panel doing the install.
        $stillProvided = $this->packagesProvidedBy($repositories);

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $manifest[$section] ?? null;
            if (! is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $package => $constraint) {
                if (! is_string($package) || ! is_string($constraint) || ! $this->isDevConstraint($constraint)) {
                    continue;
                }

                if (in_array($package, $stillProvided, true)) {
                    continue;
                }

                $replacement = $this->publicConstraint($package);

                $requirements[$package] = $replacement;
                $notes[] = "Relaxed {$package} from \"{$constraint}\" to \"{$replacement}\" — its path repository is gone.";
            }

            $manifest[$section] = $requirements;
        }

        // Kept beside the file rather than in storage/: whoever is looking at a
        // half-repaired install is looking at the project root.
        @file_put_contents($path.'.magna-backup', $raw);

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || @file_put_contents($path, $encoded."\n") === false) {
            return ['Could not repair composer.json — it names a path repository that does not exist and is not writable.'];
        }

        return $notes;
    }

    /**
     * The package names the given path repositories can still supply, read from
     * each directory's own composer.json (wildcard urls expanded).
     *
     * @param  array<mixed>  $repositories
     * @return list<string>
     */
    private function packagesProvidedBy(array $repositories): array
    {
        $names = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            foreach ((array) glob($this->absolute($url).'/composer.json') as $manifest) {
                if (! is_string($manifest)) {
                    continue;
                }

                /** @var mixed $decoded */
                $decoded = json_decode((string) @file_get_contents($manifest), true);

                if (is_array($decoded) && is_string($decoded['name'] ?? null)) {
                    $names[] = $decoded['name'];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<mixed> $repository */
    private function isMissingPathRepository(array $repository): bool
    {
        if (($repository['type'] ?? null) !== 'path') {
            return false;
        }

        $url = $repository['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return false;
        }

        // A wildcard path repository ("plugins-dev/*/*") matching nothing is not
        // an error to Composer, so there is nothing here to repair.
        if (str_contains($url, '*') || str_contains($url, '?')) {
            return false;
        }

        return ! is_dir($this->absolute($url));
    }

    private function absolute(string $url): string
    {
        // A Windows path ("C:/Users/...") is absolute here too: on Linux it is
        // not, and joining it onto the base path would still name nothing, but
        // saying so plainly keeps the message honest about what was dropped.
        $isAbsolute = str_starts_with($url, '/')
            || str_starts_with($url, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[/\\\\]#', $url) === 1;

        return $isAbsolute ? $url : $this->basePath.DIRECTORY_SEPARATOR.$url;
    }

    /** Only the constraints that Packagist alone can never satisfy. */
    private function isDevConstraint(string $constraint): bool
    {
        $constraint = trim($constraint);

        return $constraint === '@dev' || str_starts_with($constraint, 'dev-');
    }

    /**
     * A constraint the package's public releases can satisfy — taken from what
     * is already installed, so a repaired site keeps the version it is running.
     */
    private function publicConstraint(string $package): string
    {
        $version = $this->installedVersion($package);

        if ($version === null || preg_match('/^v?(\d+)\.(\d+)/', $version, $m) !== 1) {
            return '*';
        }

        return '^'.$m[1].'.'.$m[2];
    }

    private function installedVersion(string $package): ?string
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'composer'.DIRECTORY_SEPARATOR.'installed.json';

        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var mixed $packages */
        $packages = $decoded['packages'] ?? $decoded;
        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $installed) {
            if (is_array($installed) && ($installed['name'] ?? null) === $package && is_string($installed['version'] ?? null)) {
                return $installed['version'];
            }
        }

        return null;
    }
}

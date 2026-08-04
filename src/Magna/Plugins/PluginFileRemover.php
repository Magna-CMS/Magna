<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Takes a plugin's files off disk, and its wiring out of composer.json.
 *
 * Without this, "Uninstall" removed the PluginRecord and nothing else: the
 * files stayed, and the very next page load called syncDiscovered(), which
 * re-created the row from those files. The plugin came straight back, and a
 * bundled one could never be removed at all.
 *
 * Every path is re-derived from the package name and re-checked against the
 * application root before anything is deleted. The recorded base_path is a
 * hint, never an instruction — this class issues recursive deletes, which is
 * not a power to hand to a value in a database row.
 */
class PluginFileRemover
{
    public function __construct(
        private readonly string $basePath,
        private readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * Removes every directory this plugin occupies.
     *
     * @return list<string> the paths actually removed, for the audit trail
     */
    public function remove(string $name): array
    {
        PluginPackageName::assertValid($name);

        $removed = [];

        foreach ($this->candidates($name) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $this->files->remove($directory);
            $removed[] = $directory;
        }

        $this->unregisterFromComposer($name);

        if ($removed !== []) {
            Log::info('Removed plugin files during uninstall.', ['plugin' => $name, 'paths' => $removed]);
        }

        return $removed;
    }

    /**
     * The two trees a plugin may occupy. A hub ships the source in
     * plugins-dev/ and lets Composer mirror it into vendor/, so removing one
     * without the other leaves the plugin half-present — and discovery finds
     * whichever copy survived.
     *
     * @return list<string>
     */
    private function candidates(string $name): array
    {
        $root = rtrim(str_replace('\\', '/', $this->basePath), '/');

        return array_values(array_filter(
            [$root.'/plugins-dev/'.$name, $root.'/vendor/'.$name],
            fn (string $path): bool => $this->isSafeToRemove($path, $root),
        ));
    }

    /**
     * A resolved path must sit inside the application root, under one of the
     * two known trees, and be a real directory rather than a symlink pointing
     * somewhere else entirely — Composer resolves a `type: path` repository by
     * symlinking vendor/{package} at the plugins-dev/ source on hosts that
     * allow it, and following that link would delete the source twice over
     * while leaving the link behind.
     */
    private function isSafeToRemove(string $path, string $root): bool
    {
        if (is_link($path)) {
            return false;
        }

        // A .git directory means this is somebody's source checkout, not an
        // installed artifact — a release archive and a Composer install both
        // ship without one. Refusing here is what stops an uninstall wiping a
        // developer's working copy: it already destroyed plugins-dev/magna/docs
        // once, recoverable only because .git happened to survive the delete.
        if (is_dir($path.'/.git')) {
            Log::warning('Refusing to delete a plugin directory that is a git working copy.', ['path' => $path]);

            return false;
        }

        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $real);

        return str_starts_with($normalized, $root.'/plugins-dev/')
            || str_starts_with($normalized, $root.'/vendor/');
    }

    /**
     * Drops the `require` entry and any `type: path` repository pointing at
     * this plugin. Leaving them behind means the next Composer command on the
     * site fails to resolve a package whose source has just been deleted.
     *
     * Deliberately quiet on a malformed or unreadable composer.json: this runs
     * while an uninstall is completing, and refusing to finish because of it
     * would leave the plugin in a worse state than before.
     */
    private function unregisterFromComposer(string $name): void
    {
        $composerPath = $this->basePath.'/composer.json';

        $raw = @file_get_contents($composerPath);

        if ($raw === false) {
            return;
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('Could not parse composer.json while uninstalling a plugin.', ['plugin' => $name]);

            return;
        }

        /** @var array<string, mixed> $require */
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $hadRequire = array_key_exists($name, $require);
        unset($require[$name]);
        $composer['require'] = $require;

        /** @var list<array<string, mixed>> $repositories */
        $repositories = is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [];
        $kept = array_values(array_filter(
            $repositories,
            static function (array $repo) use ($name): bool {
                if (($repo['type'] ?? null) !== 'path') {
                    return true;
                }

                $url = $repo['url'] ?? null;

                // A non-string url is a malformed entry, not this plugin's —
                // keep it and let Composer complain about its own file.
                return ! is_string($url)
                    || ! str_ends_with(str_replace('\\', '/', $url), '/'.$name);
            },
        ));

        if (! $hadRequire && count($kept) === count($repositories)) {
            return;
        }

        $composer['repositories'] = $kept;

        $written = @file_put_contents(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        if ($written === false) {
            throw new RuntimeException("Removed \"{$name}\" but could not update composer.json — check file permissions.");
        }
    }
}

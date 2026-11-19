<?php

declare(strict_types=1);

namespace Magna\Updater;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Corrects the root forwarder a site was installed with.
 *
 * The .htaccess and web.config at the archive root are written once, by the
 * installing archive, and no update has ever touched them: they are not
 * core-owned, and must not become so, because a swap would take the whole file
 * and the rules a host or an operator added to it. Every release up to 1.7.2
 * denied /storage there, so a site served from the archive root answered 403
 * for every public file, and a corrected archive alone would only ever have
 * reached fresh installs.
 *
 * So the one line that was wrong is replaced, in place, by the finalizer of
 * the release that fixed it. Every archive wrote that line identically, so
 * only the exact original is touched. Anything else — the file absent, the
 * line already corrected, a list the operator rewrote — is left alone, which
 * also makes a repeat run do nothing.
 *
 * The .htaccess also gains `DirectorySlash Off` when it lacks it. Without it,
 * mod_dir answers /themes with a 301 to /themes/ as soon as a theme is
 * installed (themes/ is a real folder at the root), Laravel strips the slash
 * again, and the admin's Themes page is unreachable on a site whose panel sits
 * at the domain root. The block goes in just above the forwarder's rewrite
 * block, exactly as a fresh archive writes it, and only into a file that is
 * recognisably Magna's forwarder.
 */
final class RootForwarderRepair
{
    /** What a fresh archive's .htaccess carries above its rewrite block. */
    private const DIRECTORY_SLASH_OFF = "<IfModule mod_dir.c>\n    DirectorySlash Off\n</IfModule>\n\n";

    /** The forwarder's own rewrite block, which the directive is placed above. */
    private const REWRITE_BLOCK = '<IfModule mod_rewrite.c>';

    /** The forward rule that marks a file as Magna's root forwarder. */
    private const FORWARD_RULE = 'RewriteRule ^(.*)$ public/$1 [L]';

    /** @var array<string, array{0: string, 1: string}> */
    private const CORRECTIONS = [
        '.htaccess' => [
            'RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor)(/|$) - [F,L]',
            'RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|schemas|src|tests|vendor)(/|$) - [F,L]',
        ],
        'web.config' => [
            '<match url="^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor|\.env|\.git)(/|$)" />',
            '<match url="^(app|bootstrap|config|database|lang|resources|routes|schemas|src|tests|vendor|\.env|\.git)(/|$)" />',
        ],
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
    ) {}

    /**
     * File names by what happened; a file with nothing to correct is in neither.
     *
     * @return array{repaired: list<string>, failed: list<string>}
     */
    public function repair(): array
    {
        $report = ['repaired' => [], 'failed' => []];

        foreach (self::CORRECTIONS as $file => [$denied, $corrected]) {
            $path = $this->paths->base($file);

            if (! is_file($path)) {
                continue;
            }

            try {
                $original = $this->files->readFile($path);
                $contents = str_replace($denied, $corrected, $original);

                if ($file === '.htaccess') {
                    $contents = $this->withDirectorySlashOff($contents);
                }

                if ($contents === $original) {
                    continue;
                }

                $this->files->dumpFile($path, $contents);
                $report['repaired'][] = $file;
            } catch (IOException) {
                $report['failed'][] = $file;
            }
        }

        return $report;
    }

    /**
     * The forwarder with `DirectorySlash Off` above its rewrite block, or the
     * file as it was when it already has the directive or is not Magna's.
     */
    private function withDirectorySlashOff(string $contents): string
    {
        if (str_contains($contents, 'DirectorySlash')
            || ! str_contains($contents, self::FORWARD_RULE)
            || ! str_contains($contents, self::REWRITE_BLOCK)) {
            return $contents;
        }

        $at = (int) strpos($contents, self::REWRITE_BLOCK);

        return substr($contents, 0, $at).self::DIRECTORY_SLASH_OFF.substr($contents, $at);
    }
}

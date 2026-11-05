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
 */
final class RootForwarderRepair
{
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
                $contents = $this->files->readFile($path);

                if (! str_contains($contents, $denied)) {
                    continue;
                }

                $this->files->dumpFile($path, str_replace($denied, $corrected, $contents));
                $report['repaired'][] = $file;
            } catch (IOException) {
                $report['failed'][] = $file;
            }
        }

        return $report;
    }
}

<?php

declare(strict_types=1);

namespace Magna\Updater\Manifest;

use RuntimeException;

/** A magna-release.json that cannot be acted on, with every reason listed. */
final class InvalidReleaseManifestException extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The release manifest is not usable: '.implode(' ', $problems));
    }
}

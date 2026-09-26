<?php

declare(strict_types=1);

namespace Magna\Updater;

/**
 * Why a release archive is being applied.
 *
 * An update moves the install forward and refuses anything that does not. A
 * repair re-applies the release the install already runs — for a site an
 * older updater left with the code but not everything the code expected —
 * and refuses anything else.
 */
enum UpdateMode: string
{
    case Update = 'update';
    case Repair = 'repair';
}

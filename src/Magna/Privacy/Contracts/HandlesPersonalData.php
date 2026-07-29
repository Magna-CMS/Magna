<?php

declare(strict_types=1);

namespace Magna\Privacy\Contracts;

use Magna\Users\User;

/**
 * @deprecated since 1.x — implement \Magna\Contracts\HandlesPersonalData (plugin SDK) instead.
 *             Scheduled for removal in 2.0.
 *
 * Retained purely for backward compatibility. The canonical contract moved to
 * the plugin SDK and widened its parameter from the concrete core User model to
 * Laravel's Authenticatable, so this interface cannot simply extend it — the
 * narrower parameter type would violate LSP and fail to compile.
 *
 * It is kept as a standalone declaration because removing it outright is worse
 * than a duplicate: a plugin compiled against the old interface would silently
 * stop matching `instanceof` in PrivacyEraseCommand, which does not merely skip
 * the plugin — with `--force` it anonymises the user while leaving that
 * plugin's personal data in place, i.e. an erasure reported as complete that
 * is not. Both commands therefore accept either contract; see
 * PrivacyEraseCommand::handlesPersonalData().
 * @see \Magna\Contracts\HandlesPersonalData
 */
interface HandlesPersonalData
{
    /** @return array<string, mixed> */
    public function exportPersonalData(User $user): array;

    public function erasePersonalData(User $user): void;
}

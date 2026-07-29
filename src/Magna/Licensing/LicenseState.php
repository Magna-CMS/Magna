<?php

declare(strict_types=1);

namespace Magna\Licensing;

/**
 * What a paid product may do on this site right now.
 *
 * Two policies live here, and the difference matters:
 *
 *  • A MARKETPLACE licence that simply lapses (annual not renewed) leaves the
 *    installed code running and stops updates — the WordPress-ecosystem norm,
 *    and the thing that kills the "my site broke when my licence expired"
 *    support ticket.
 *  • A CORPORATE or TRIAL licence that ends stops the product outright, as
 *    does any revocation or suspension. Corporate delivery is contractual:
 *    when the developer or the operator ends it, the client's site must stop
 *    using the product, with the reason on screen.
 *
 * Neither policy ever destroys data, and no network failure alone reaches
 * either — that is what Grace is for.
 */
enum LicenseState: string
{
    /** Verified within the re-check window. Everything works. */
    case Valid = 'valid';

    /** Server unreachable, still inside the offline window. Everything works; warn. */
    case Grace = 'grace';

    /** Marketplace term lapsed. Installed code keeps running; updates stop. */
    case Expired = 'expired';

    /** Revoked, suspended, or an ended corporate/trial term. Product stops. */
    case Locked = 'locked';

    /** No licence on this site for this product at all. */
    case Unlicensed = 'unlicensed';

    /** The product may load and run. */
    public function featuresEnabled(): bool
    {
        return match ($this) {
            self::Valid, self::Grace, self::Expired => true,
            self::Locked, self::Unlicensed => false,
        };
    }

    /** Updates and package downloads may be served. */
    public function canUpdate(): bool
    {
        return $this === self::Valid || $this === self::Grace;
    }

    /** The admin should show a banner about this state. */
    public function needsAttention(): bool
    {
        return $this !== self::Valid;
    }
}

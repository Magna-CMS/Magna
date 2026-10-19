<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * A resolved route into a plugin frontend page: the page definition plus
 * whatever `{param}` segments the path captured. What
 * FrontendPageRegistry::resolve() hands the Pages router.
 */
final class FrontendPageMatch
{
    /** @param  array<string, string>  $params  Captured path segments, by token name. */
    public function __construct(
        public readonly FrontendPage $page,
        public readonly array $params = [],
    ) {}
}

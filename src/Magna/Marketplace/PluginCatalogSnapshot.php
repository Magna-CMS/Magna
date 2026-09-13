<?php

declare(strict_types=1);

namespace Magna\Marketplace;

/** What the Plugins page renders: built by PluginCatalogView, in one pass. */
final readonly class PluginCatalogSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $installed
     * @param  list<array<string, mixed>>  $available
     */
    public function __construct(
        public array $installed,
        public array $available,
        public bool $marketplaceUnreachable,
    ) {}
}

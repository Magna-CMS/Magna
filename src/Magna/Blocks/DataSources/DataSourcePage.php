<?php

declare(strict_types=1);

namespace Magna\Blocks\DataSources;

/** One page of a PaginatedDataSource: its items and the total across all pages. */
final class DataSourcePage
{
    /** @param  list<array<string, string>>  $items  Same conventional keys as DataSource::fetch(). */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {}
}

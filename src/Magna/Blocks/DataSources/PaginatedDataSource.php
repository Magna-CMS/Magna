<?php

declare(strict_types=1);

namespace Magna\Blocks\DataSources;

/**
 * A data source that can serve one page of its feed and say how long the
 * whole feed is — what a Loop block set to paginate needs. Optional: a
 * source that only implements DataSource keeps working, unpaginated.
 *
 * `$config` carries the same conventional keys fetch() sees (`limit`,
 * `locale`); `$perPage` is already resolved from them by the caller.
 */
interface PaginatedDataSource extends DataSource
{
    /**
     * @param  array<string, mixed>  $config  The loop block's data.
     * @param  int  $page  1-based.
     */
    public function fetchPage(array $config, int $page, int $perPage): DataSourcePage;
}

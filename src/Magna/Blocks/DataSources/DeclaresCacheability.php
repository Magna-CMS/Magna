<?php

declare(strict_types=1);

namespace Magna\Blocks\DataSources;

use DateTimeInterface;

/**
 * A data source that tells the page cache how its output changes, so a
 * page listing it can be cached and still never show stale content.
 * Optional; without it a listing page is cached under the page's own
 * tags and expires on the site TTL.
 */
interface DeclaresCacheability
{
    /**
     * Tags a cached page listing this source answers to. The source's
     * plugin dispatches Magna\Frontend\Events\PublicContentChanged with
     * the same tags when the content behind them changes.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function cacheTags(array $config): array;

    /**
     * The next moment the output changes with nobody saving anything — a
     * post scheduled to go live, say. Null when nothing is scheduled.
     *
     * @param  array<string, mixed>  $config
     */
    public function nextChangeAt(array $config): ?DateTimeInterface;
}

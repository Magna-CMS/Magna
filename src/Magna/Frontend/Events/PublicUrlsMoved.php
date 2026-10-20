<?php

declare(strict_types=1);

namespace Magna\Frontend\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Magna\Frontend\UrlMove;

/**
 * Public URLs moved — a post's slug changed, a permalink pattern was
 * edited — and each old path should redirect to its new one so links
 * and search results keep working. Whichever plugin serves redirects
 * listens; the plugin that moved the URLs never needs to know which.
 */
final class PublicUrlsMoved
{
    use Dispatchable;

    /** @var list<UrlMove> */
    public readonly array $moves;

    /** @param  list<UrlMove>  $moves */
    public function __construct(
        array $moves,
        /** Who moved them — for logs only. */
        public readonly string $source = '',
    ) {
        $this->moves = array_values(array_filter($moves, static fn (UrlMove $move): bool => ! $move->isNoop()));
    }
}

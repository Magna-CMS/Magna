<?php

declare(strict_types=1);

namespace Magna\Frontend;

use InvalidArgumentException;

/**
 * A public URL that now lives somewhere else: the old site-relative path,
 * the new one, and the status a redirect should answer with.
 */
final class UrlMove
{
    public readonly string $from;

    public readonly string $to;

    public function __construct(string $from, string $to, public readonly int $status = 301)
    {
        if (! in_array($status, [301, 302, 307, 308], true)) {
            throw new InvalidArgumentException("A URL move cannot answer {$status}.");
        }

        $this->from = '/'.trim($from, '/');
        $this->to = '/'.trim($to, '/');
    }

    /** A move onto itself is no move — and would be a redirect loop. */
    public function isNoop(): bool
    {
        return $this->from === $this->to;
    }
}

<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * One page of a paginated public listing, and the URLs around it.
 *
 * Page 1 is always the bare path — never `?page=1` — so a listing has one
 * canonical URL and a page cache keys it once. Every other page is
 * `?page=N`, the only query string the Pages cache will store a response
 * for. Pages stop at RenderContext::MAX_PAGE: past that, a crawler walking
 * `?page=` would mint cache entries without end.
 */
final class Pagination
{
    public readonly int $lastPage;

    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        /** Site-relative path the listing lives at, locale prefix included. */
        public readonly string $path,
    ) {
        $this->lastPage = min(RenderContext::MAX_PAGE, max(1, (int) ceil($total / max(1, $perPage))));
    }

    /** Whether there is more than one page to move between. */
    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1 && $this->page <= $this->lastPage;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage;
    }

    public function previousUrl(): ?string
    {
        return $this->hasPrevious() ? $this->urlFor($this->page - 1) : null;
    }

    public function nextUrl(): ?string
    {
        return $this->hasNext() ? $this->urlFor($this->page + 1) : null;
    }

    public function urlFor(int $page): string
    {
        $path = '/'.trim($this->path, '/');

        return $page <= 1 ? $path : $path.'?page='.$page;
    }

    /** Rows to skip for this page. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** A page past the end — the response is a 404, not an empty list. */
    public function isOutOfRange(): bool
    {
        return $this->page > $this->lastPage;
    }
}

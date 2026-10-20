<?php

declare(strict_types=1);

namespace Magna\Frontend;

use DateTimeInterface;

/**
 * What a FrontendPageDataProvider hands back: the view's data, plus the
 * per-request title and meta description a parameterized page cannot carry
 * statically — "Blog post" is a definition's title, the post's own name is
 * a result's.
 *
 * The trailing arguments (1.6.2) let a provider opt its response into the
 * site's page cache. A result is uncached unless it says otherwise: only
 * the provider knows whether what it rendered is the same for every guest.
 */
final class FrontendPageResult
{
    /**
     * @param  array<string, mixed>  $data  View data for the page's Blade view.
     * @param  list<string>  $cacheTags  Purge tags (PublicContentChanged) the cached response answers to.
     * @param  array<string, string>  $localeAlternates  Locale code => site-relative URL of this page in that locale.
     */
    public function __construct(
        public readonly array $data = [],
        /** Overrides FrontendPage::$title for this response when set. */
        public readonly ?string $title = null,
        /** Meta description for this response; a static page has none. */
        public readonly ?string $metaDescription = null,
        /** Whether a guest's response may be stored by the page cache. */
        public readonly bool $cacheable = false,
        public readonly array $cacheTags = [],
        /** When the cached response goes stale on its own (a scheduled change). */
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly array $localeAlternates = [],
    ) {}
}

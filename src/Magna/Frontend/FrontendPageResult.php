<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * What a FrontendPageDataProvider hands back: the view's data, plus the
 * per-request title and meta description a parameterized page cannot carry
 * statically — "Blog post" is a definition's title, the post's own name is
 * a result's.
 */
final class FrontendPageResult
{
    /** @param  array<string, mixed>  $data  View data for the page's Blade view. */
    public function __construct(
        public readonly array $data = [],
        /** Overrides FrontendPage::$title for this response when set. */
        public readonly ?string $title = null,
        /** Meta description for this response; a static page has none. */
        public readonly ?string $metaDescription = null,
    ) {}
}

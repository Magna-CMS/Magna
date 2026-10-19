<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * Turns a parameterized frontend page's captured path segments into the
 * data its view renders — the "controller" half the extensibility doc
 * reserved on ProvidesFrontendPages (docs/magna-pages/07-EXTENSIBILITY.md
 * §2). Named by FrontendPage::$controller and resolved from the container
 * per request, after the page's auth/permission gates have passed — an
 * unauthorized visitor must never cost a query.
 */
interface FrontendPageDataProvider
{
    /**
     * Data for the page's view, or null when the parameters name nothing —
     * an unknown slug falls through to the site's redirects and 404 page
     * rather than rendering an empty shell.
     *
     * @param  array<string, string>  $params  Captured path segments, by token name.
     */
    public function resolve(array $params): ?FrontendPageResult;
}

<?php

declare(strict_types=1);

namespace Magna\Blocks\DynamicTags;

/**
 * A named dynamic value a plugin exposes to page building — what a field
 * binding (`{"$bind": "tag.<handle>"}`) resolves to at render time
 * (docs/magna-pages/07-EXTENSIBILITY.md).
 *
 * A tag returns one presentation-ready STRING and declares how cacheable
 * that string is; the page cache folds every bound tag's declaration into
 * its verdict, so a per-user value can never leak through a shared cache.
 *
 * resolve() runs on the PUBLIC render path: return only what the current
 * visitor may see. A tag needing the authenticated user must declare
 * CACHE_USER — that is what keeps its output out of the shared cache.
 */
interface DynamicTag
{
    /** Safe in the shared page cache for any visitor. */
    public const CACHE_STATIC = 'static';

    /** Varies per page but not per visitor — still shared-cache safe. */
    public const CACHE_PAGE = 'page';

    /** Varies per visitor — the page rendering it must not be cached. */
    public const CACHE_USER = 'user';

    /** Unique handle, `vendor.name` style (`chat.unread_count`). */
    public function handle(): string;

    /** Human label for pickers. */
    public function label(): string;

    /** One of the CACHE_* constants. */
    public function cacheability(): string;

    /** The current value, as a presentation string. */
    public function resolve(): string;
}

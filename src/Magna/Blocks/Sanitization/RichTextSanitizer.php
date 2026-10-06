<?php

declare(strict_types=1);

namespace Magna\Blocks\Sanitization;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlist HTML sanitizer for richtext content (the `text` block body and,
 * as they arrive, richtext fields generally).
 *
 * Sanitized richtext is the only path to unescaped output that does NOT
 * require the blocks.raw_html permission — the sanitizer is the security
 * boundary that lets content-tier editors write formatted text without
 * being handed script capability
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §C1). Only the `html` block
 * remains raw, and it keeps its permission gate.
 *
 * Built on symfony/html-sanitizer (W3C Sanitizer API semantics: DOM-parsed,
 * default-deny, drops <script>, on* handlers, and dangerous URL schemes by
 * construction). One curated profile for v1 — per-role allowlist profiles
 * are a later phase.
 */
final class RichTextSanitizer
{
    /**
     * Generous cap so long-form posts are never silently truncated
     * (the Symfony default of 20k characters would be).
     */
    private const MAX_INPUT_LENGTH = 1_000_000;

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            // W3C-safe baseline: formatting, headings, lists, tables, links,
            // images — everything script-capable is dropped.
            ->allowSafeElements()
            // Scheme allowlist aligned with Magna\Blocks\Support\SafeUrl.
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https'])
            // A relative URL has no scheme, and a scheme allowlist alone
            // drops it — so `<a href="/pricing">` was stored and rendered as
            // `<a rel="noopener noreferrer">`: the words survived, the
            // destination did not, and nothing said so. Linking to another
            // page of your own site is the most ordinary thing a CMS does,
            // and it failed silently every time.
            //
            // The same for media: an image dragged from the library carries
            // `/storage/...`, which was losing its src for the identical
            // reason and rendering blank.
            //
            // This is what the line above has always claimed. SafeUrl, which
            // guards the URL FIELDS, allowlists these schemes AND passes
            // relative through (SafeUrl::sanitize returns the URL unchanged
            // when parse_url reports no scheme). A link typed into prose and
            // a link typed into a button field now behave the same way.
            // `javascript:` is unaffected: it HAS a scheme, and that scheme
            // is not on the list.
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}

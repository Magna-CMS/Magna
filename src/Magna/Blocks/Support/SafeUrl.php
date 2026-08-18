<?php

declare(strict_types=1);

namespace Magna\Blocks\Support;

/**
 * URL sanitizer for block Blade templates.
 *
 * Blade's {{ }} escapes HTML special characters but is transparent to the
 * `javascript:` scheme — a value like `javascript:alert(1)` renders into
 * an href that the browser executes on click. This class enforces an
 * explicit scheme allowlist so block URL fields cannot inject script URLs.
 */
final class SafeUrl
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Return the URL (whitespace-normalised) if its scheme is safe, or '#'
     * if it is not.
     *
     * Relative URLs (no scheme, or starting with '/', '#', './') pass through.
     */
    public static function sanitize(mixed $url, string $fallback = '#'): string
    {
        if (! is_string($url) || $url === '') {
            return $fallback;
        }

        // Browsers preprocess an href before parsing its scheme (WHATWG URL
        // spec): leading/trailing C0 controls and spaces are stripped, and
        // every tab, LF, and CR is removed wherever it appears. That makes
        // " javascript:x" and "java\tscript:x" executable even though
        // parse_url() reports no scheme for them. Mirror the browser's
        // preprocessing, allowlist the scheme of the CLEANED value, and
        // return the cleaned value — so the scheme we checked is the scheme
        // the browser will act on.
        $url = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));

        if ($url === '') {
            return $fallback;
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if ($scheme === '') {
            return $url;
        }

        return in_array($scheme, self::ALLOWED_SCHEMES, true) ? $url : $fallback;
    }
}

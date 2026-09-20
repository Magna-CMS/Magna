<?php

declare(strict_types=1);

namespace Magna\Media;

/**
 * The one answer to "may a browser render this media type inline from our
 * origin?". MediaUrlResolver uses it to decide whether a public-disk URL may
 * point straight at storage, and MediaServeController uses it to decide
 * whether to force a download — two callers that previously kept separate
 * lists, which is how every type outside the serve controller's list ended
 * up reachable as a raw disk URL carrying no headers at all.
 *
 * Inline-safe means: the browser treats the bytes as media, never as a
 * document. Markup-capable types (SVG, HTML) and everything the ingestor
 * stores verbatim without a payload-stripping re-encode stay out; they are
 * served through MediaServeController as attachments under nosniff.
 */
final class MediaTypePolicy
{
    /**
     * Raster formats a browser decodes as pixels, the PDF viewer, and the
     * non-executable audio/video containers. Nothing here can carry an
     * active payload the browser would run in our origin.
     */
    private const INLINE_SAFE = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/avif',
        'application/pdf',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/flac',
        'audio/aac',
    ];

    public static function inlineSafe(string $mime): bool
    {
        return in_array($mime, self::INLINE_SAFE, true);
    }
}

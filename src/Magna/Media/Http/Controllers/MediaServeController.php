<?php

declare(strict_types=1);

namespace Magna\Media\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a media file from storage, enforcing safe delivery headers.
 *
 * SVG files are always served as attachments (Content-Disposition: attachment)
 * regardless of web server configuration. This prevents stored XSS if a
 * sanitizer bypass ever reaches storage — the browser downloads the file
 * instead of rendering it inline in the admin's security origin.
 *
 * Used by two routes:
 *   magna.media.serve        — signed, expiring (private disks)
 *   magna.media.serve.public — unsigned, permanent (public disk SVGs only)
 *
 * That second route is the reason this controller enforces its own contract
 * rather than trusting the route definition: it has no signature, so without
 * the check below it would serve ANY media row — including one on a private
 * disk that is only ever meant to be reachable through a signed URL. Media
 * IDs are ULIDs, but IDs leak (API payloads, srcsets, logs, referrers), and
 * an unguessable identifier is not an access control.
 */
class MediaServeController extends Controller
{
    /** Disks whose contents are already world-readable over HTTP. */
    private const PUBLIC_DISKS = ['public', 's3', 'r2', 'gcs'];

    public function __construct(private readonly MediaUrlResolver $urls) {}

    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        if ($request->route()?->getName() === 'magna.media.serve.public') {
            abort_unless($this->isPubliclyServable($media), 404);
        }

        $disk = Storage::disk($media->disk);
        $path = $this->resolvePath($media, $request->string('preset')->toString());

        if ($media->mime_type === 'image/svg+xml') {
            // Force download: browser must not render SVGs inline from our origin.
            $filename = $media->original_filename ?? basename($path);

            return $disk->download($path, $filename, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->response($path, null, $this->deliveryHeaders($media));
    }

    /**
     * The unsigned route exists for exactly one case — an SVG on a public disk,
     * which the resolver routes here only so the attachment header is
     * guaranteed. Anything else must go through the signed route.
     */
    private function isPubliclyServable(Media $media): bool
    {
        return $media->mime_type === 'image/svg+xml'
            && in_array($media->disk, self::PUBLIC_DISKS, true);
    }

    /**
     * Signed URLs are minted with the preset they were signed for
     * (MediaUrlResolver::signedUrl), so honouring it here is what makes a
     * thumbnail URL actually serve the thumbnail instead of the full-size
     * original. An unknown preset is a 404, not a silent fallback — falling
     * back would stream a multi-megabyte original to a request that asked for
     * a 200px crop.
     */
    private function resolvePath(Media $media, string $preset): string
    {
        if ($preset === '') {
            return $media->path;
        }

        $path = $this->urls->conversionPath($media, $preset);

        abort_if($path === null, 404);

        return $path;
    }

    /**
     * `nosniff` on every response, not just SVG: content-type sniffing is what
     * turns a permitted-but-crafted upload into script execution in our origin,
     * and it costs nothing to send. Anything outside the inline-safe list is
     * additionally forced to download.
     *
     * @return array<string, string>
     */
    private function deliveryHeaders(Media $media): array
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $inlineSafe = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'image/avif', 'application/pdf', 'video/mp4', 'audio/mpeg',
        ];

        if (! in_array((string) $media->mime_type, $inlineSafe, true)) {
            $filename = $media->original_filename ?? basename($media->path);
            $headers['Content-Disposition'] = 'attachment; filename="'.addslashes($filename).'"';
        }

        return $headers;
    }
}

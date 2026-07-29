<?php

declare(strict_types=1);

namespace Magna\Media;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Magna\Settings\UrlSettings;

class MediaUrlResolver
{
    /**
     * Return a public (non-signed) URL for the media or one of its presets.
     *
     * For public disks the URL is permanent and may be served with immutable
     * cache headers. Original is served when no conversion exists.
     */
    public function publicUrl(Media $media, ?string $preset = null): string
    {
        $path = $media->path;

        if ($preset !== null) {
            $conversion = MediaConversion::where('media_id', $media->id)
                ->where('preset', $preset)
                ->where('format', 'webp')
                ->first();

            if ($conversion !== null) {
                $path = $conversion->path;
            }
        }

        $cdnUrl = UrlSettings::get()->cdn_url;
        if ($cdnUrl !== '') {
            return rtrim($cdnUrl, '/').'/'.ltrim($path, '/');
        }

        // SVGs on non-CDN disks are routed through the serve controller so that
        // Content-Disposition: attachment is always sent, regardless of web server.
        // This prevents inline rendering even if the sanitizer is ever bypassed.
        if ($media->mime_type === 'image/svg+xml' && Route::has('magna.media.serve.public')) {
            return URL::route('magna.media.serve.public', ['media' => $media->id]);
        }

        return Storage::disk($media->disk)->url($path);
    }

    /**
     * Return a signed, expiring URL for the media (private disk delivery).
     *
     * For S3-compatible disks the SDK's native temporaryUrl() is used.
     * For all other disks a signed Laravel route is generated.
     */
    public function signedUrl(
        Media $media,
        ?string $preset = null,
        ?Carbon $expiresAt = null,
    ): string {
        $expiresAt ??= now()->addHour();

        if (in_array($media->disk, ['s3', 'r2', 'gcs'], true)) {
            $path = $preset !== null ? $this->conversionPath($media, $preset) : null;

            return Storage::disk($media->disk)->temporaryUrl(
                $path ?? $media->path,
                $expiresAt,
            );
        }

        return URL::temporarySignedRoute(
            'magna.media.serve',
            $expiresAt,
            ['media' => $media->id, 'preset' => $preset],
        );
    }

    /**
     * Build a responsive srcset string from all WebP conversions for the media.
     * Returns an empty string when no conversions exist yet.
     */
    public function srcset(Media $media): string
    {
        $conversions = MediaConversion::where('media_id', $media->id)
            ->where('format', 'webp')
            ->orderBy('width')
            ->get();

        if ($conversions->isEmpty()) {
            return '';
        }

        $cdnUrl = UrlSettings::get()->cdn_url;

        return $conversions
            ->map(function (MediaConversion $c) use ($media, $cdnUrl): string {
                $url = $cdnUrl !== ''
                    ? rtrim($cdnUrl, '/').'/'.ltrim($c->path, '/')
                    : Storage::disk($media->disk)->url($c->path);

                return $url.' '.$c->width.'w';
            })
            ->implode(', ');
    }

    public function conversionPath(Media $media, string $preset): ?string
    {
        $conversion = MediaConversion::where('media_id', $media->id)
            ->where('preset', $preset)
            ->where('format', 'webp')
            ->first();

        return $conversion?->path;
    }
}

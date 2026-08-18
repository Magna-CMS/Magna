<?php

declare(strict_types=1);

namespace Magna\Media;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Magna\Media\Exceptions\MediaIngestException;
use Magna\Media\Exceptions\MimeTypeNotAllowedException;
use Magna\Media\Jobs\ProcessMediaConversionJob;
use Magna\Settings\MediaSettings;

class MediaIngestor
{
    /**
     * Content-sniffed MIME types that are allowed through the upload pipeline.
     * Never trust file extensions — only trust what finfo reports.
     *
     * @var list<string>
     */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/svg+xml',
        'application/pdf',
        // Media & document types for the Blog plugin's video / audio / file blocks.
        // These are stored verbatim (not re-encoded); only rasters and SVG are
        // processed for safety.
        'video/mp4',
        'video/webm',
        'video/ogg',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Raster image MIME types that must be re-encoded on ingest to strip
     * embedded payloads, EXIF, and malicious metadata.
     *
     * @var list<string>
     */
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /** Resolves per-MIME size limits from MediaSettings at ingest time. */
    private function maxSizeFor(string $mime): int
    {
        $s = MediaSettings::get();

        // Video and audio reuse the document size cap (raise
        // MediaSettings::max_document_upload_bytes if larger files are needed).
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return $s->max_document_upload_bytes;
        }

        return match ($mime) {
            'image/svg+xml' => $s->max_svg_upload_bytes,
            'application/pdf',
            'application/zip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $s->max_document_upload_bytes,
            default => $s->max_image_upload_bytes,
        };
    }

    /** Canonical extensions, keyed by MIME type. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'oga',
        'audio/wav' => 'wav',
        'audio/webm' => 'weba',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly ConversionPresetRegistry $presets,
        private readonly string $defaultDisk = 'public',
    ) {}

    /**
     * Ingest a file from $sourcePath into managed media storage.
     *
     * The pipeline is (in order):
     *   1. Content-sniff the MIME type (never trust the extension)
     *   2. Allowlist check — reject unknown types immediately
     *   3. Size guard per MIME type
     *   4. Process: raster images are re-encoded (strips EXIF + embedded payloads);
     *      SVG is sanitized (strips scripts + dangerous attributes); other types pass through
     *   5. Store on disk at a content-addressed path
     *   6. Persist a Media record
     *   7. Dispatch queued conversion jobs for raster images
     *
     * @throws MimeTypeNotAllowedException
     * @throws MediaIngestException
     */
    public function ingest(
        string $sourcePath,
        string $originalFilename,
        ?string $disk = null,
        ?string $folderId = null,
        ?string $alt = null,
        ?string $title = null,
    ): Media {
        // 1. Content-sniff — SECURITY: never trust the file extension.
        $mime = $this->sniffMimeType($sourcePath);

        // 2. Allowlist
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new MimeTypeNotAllowedException($mime, $originalFilename);
        }

        // 3. Size guard
        $this->guardFileSize($sourcePath, $originalFilename, $mime);

        $disk ??= $this->defaultDisk;

        // 4. Process
        [$content, $width, $height] = match (true) {
            in_array($mime, self::IMAGE_MIMES, true) => $this->processImage($sourcePath, $mime),
            $mime === 'image/svg+xml' => $this->processSvg($sourcePath),
            default => $this->readRaw($sourcePath),
        };

        // 5. Store at a content-addressed path using the pre-allocated ULID
        $mediaId = (string) Str::ulid();
        $storagePath = $this->storagePathFor($mediaId, $mime);
        Storage::disk($disk)->put($storagePath, $content);

        // 6. Persist
        $media = $this->buildMediaRecord(
            id: $mediaId,
            disk: $disk,
            storagePath: $storagePath,
            originalFilename: $originalFilename,
            mime: $mime,
            content: $content,
            width: $width,
            height: $height,
            folderId: $folderId,
            alt: $alt,
            title: $title,
        );

        // 7. Dispatch conversions for raster images (SVG/PDF do not get presets)
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            $this->dispatchConversions($media);
        }

        return $media;
    }

    private function guardFileSize(string $sourcePath, string $originalFilename, string $mime): void
    {
        $fileSize = filesize($sourcePath);
        if ($fileSize === false) {
            throw new MediaIngestException("Cannot read file size for \"{$originalFilename}\".");
        }

        $maxBytes = $this->maxSizeFor($mime);
        if ($fileSize > $maxBytes) {
            throw new MediaIngestException(
                "File \"{$originalFilename}\" exceeds the ".number_format($maxBytes / 1_048_576, 0).' MB limit for '.$mime.'.'
            );
        }
    }

    private function storagePathFor(string $mediaId, string $mime): string
    {
        $ext = self::MIME_EXTENSIONS[$mime];

        return 'media/'.now()->format('Y/m').'/'.$mediaId.'.'.$ext;
    }

    private function buildMediaRecord(
        string $id,
        string $disk,
        string $storagePath,
        string $originalFilename,
        string $mime,
        string $content,
        ?int $width,
        ?int $height,
        ?string $folderId,
        ?string $alt,
        ?string $title,
    ): Media {
        $media = new Media;
        $media->id = $id;
        $media->folder_id = $folderId;
        $media->uploaded_by = auth()->id() !== null ? (string) auth()->id() : null;
        $media->disk = $disk;
        $media->path = $storagePath;
        $media->filename = basename($storagePath);
        $media->original_filename = $originalFilename;
        $media->mime_type = $mime;
        $media->size = strlen($content);
        $media->width = $width;
        $media->height = $height;
        $media->alt = $alt;
        $media->title = $title;
        $media->save();

        return $media;
    }

    private function dispatchConversions(Media $media): void
    {
        foreach ($this->presets->all() as $preset) {
            ProcessMediaConversionJob::dispatch($media->id, $preset->name);
        }
    }

    // ── Private pipeline steps ────────────────────────────────────────────────

    private function sniffMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new MediaIngestException('Failed to open finfo for MIME type detection.');
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /**
     * A compressed file's byte size says nothing about its decoded pixel
     * footprint — a small, cleverly-compressed image can declare an
     * enormous canvas ("pixel flood") and drive a multi-gigabyte allocation
     * the moment something decodes it. getimagesize() only reads the
     * header, so this check is cheap even for a hostile file.
     */
    private const MAX_IMAGE_PIXELS = 40_000_000; // e.g. ~8000x5000

    /**
     * Re-encode a raster image to strip EXIF, embedded scripts, and ICC payloads.
     * Creating a brand-new image from raw pixel data guarantees no metadata survives.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function processImage(string $sourcePath, string $mime): array
    {
        // Dimension guard BEFORE decode — see MAX_IMAGE_PIXELS docblock.
        $dimensions = @getimagesize($sourcePath);
        if (is_array($dimensions) && $dimensions[0] > 0 && $dimensions[1] > 0
            && $dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS
        ) {
            throw new MediaIngestException(
                "Image dimensions ({$dimensions[0]}x{$dimensions[1]}) exceed the ".number_format(self::MAX_IMAGE_PIXELS).'-pixel limit.',
            );
        }

        $manager = new ImageManager(new Driver);

        try {
            $image = $manager->read($sourcePath);
        } catch (\Throwable $e) {
            // A file that content-sniffs as a supported image MIME but
            // isn't actually well-formed (polyglot, truncated, corrupt)
            // previously threw a raw decoder exception straight out of
            // ingest() instead of the intended MediaIngestException,
            // 500-ing the upload endpoint instead of a clean validation error.
            throw new MediaIngestException("Could not decode image: {$e->getMessage()}", previous: $e);
        }

        $width = $image->width();
        $height = $image->height();

        $quality = MediaSettings::get()->default_image_quality;

        $encoded = match ($mime) {
            'image/jpeg' => $image->toJpeg($quality),
            'image/png' => $image->toPng(),
            'image/gif' => $image->toGif(),
            'image/webp' => $image->toWebp($quality),
            'image/avif' => $image->toAvif($quality),
            default => throw new MediaIngestException("Unsupported image MIME: {$mime}"),
        };

        return [(string) $encoded, $width, $height];
    }

    /**
     * Sanitize SVG markup: removes scripts, event handlers, and foreign objects.
     *
     * @return array{0: string, 1: null, 2: null}
     */
    private function processSvg(string $sourcePath): array
    {
        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            throw new MediaIngestException('Cannot read SVG source file.');
        }

        $sanitizer = new Sanitizer;
        $sanitizer->minify(false);
        // Without this, CSS-style url('...') references in presentation
        // attributes (fill, stroke, filter, mask, etc.) survive
        // sanitization. SVGs are force-downloaded rather than rendered
        // inline (see MediaServeController), so this isn't same-origin
        // XSS, but a staff member opening the downloaded file locally
        // would still fire a beacon request to an attacker-chosen URL,
        // leaking their IP/UA. Note: this does NOT block a plain
        // <image xlink:href="https://...">/<use> external reference —
        // the library's isHrefSafeValue() explicitly allows http(s) hrefs,
        // since referencing an external image is legitimate SVG use; fully
        // closing that would mean stripping <image>/<use> external
        // references outright, at the cost of breaking legitimate SVGs
        // that embed external images. Left as accepted risk — see
        // docs/SECURITY_AUDIT.md Stage 9.
        $sanitizer->removeRemoteReferences(true);
        $cleaned = $sanitizer->sanitize($raw);

        if ($cleaned === false || trim($cleaned) === '') {
            throw new MediaIngestException('SVG could not be sanitized — file may be malformed or contain only disallowed elements.');
        }

        return [$cleaned, null, null];
    }

    /**
     * @return array{0: string, 1: null, 2: null}
     */
    private function readRaw(string $sourcePath): array
    {
        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new MediaIngestException('Cannot read source file.');
        }

        return [$content, null, null];
    }
}

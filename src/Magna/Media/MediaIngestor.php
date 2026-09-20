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
        /*
         * The rest of what a working document library is asked to hold.
         *
         * Added because a staff collection that refuses a phone photo, a slide
         * deck or a CSV is not a library. The allowlist stays an allowlist: it
         * is still content-sniffed rather than trusted from the extension, and
         * what is deliberately absent is anything a browser or a host can
         * execute — .exe, .msi, .sh, .bat, PHP and JavaScript source, HTML.
         * Those travel inside a zip, which is allowed, rather than being served
         * back as themselves.
         *
         * Images here that are not in IMAGE_MIMES below are stored verbatim,
         * because no driver on a stock install can decode them to re-encode
         * them. They are served as attachments, so bytes that turn out to be
         * something other than a picture are downloaded rather than rendered.
         */
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/bmp',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-powerpoint',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
        'text/csv',
        'text/plain',
        'application/x-7z-compressed',
        'application/vnd.rar',
        'application/gzip',
        'application/x-tar',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'audio/mp4',
        'audio/flac',
        'audio/aac',
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

        if ($mime === 'image/svg+xml') {
            return $s->max_svg_upload_bytes;
        }

        // Everything that is not a picture is a document as far as the ceiling
        // is concerned. Asked this way round rather than by listing every type,
        // because a list is what fell out of step when the allowlist grew: a
        // spreadsheet added to one and not the other silently inherited the
        // image limit, which is the smaller of the two.
        return str_starts_with($mime, 'image/')
            ? $s->max_image_upload_bytes
            : $s->max_document_upload_bytes;
    }

    /**
     * Whether this build can actually decode a raster well enough to re-encode it.
     *
     * The re-encode is a security step, not a convenience — it is what strips
     * EXIF and any payload smuggled into the file. It can only happen where the
     * image driver understands the format, and a stock GD build routinely does
     * not understand AVIF. Re-encoding was attempted regardless, so an AVIF
     * upload failed on a server whose allowlist said it was welcome.
     *
     * A format this build cannot open is REFUSED, with a message naming the
     * missing driver support. It used to be stored verbatim on the theory the
     * serve path forces a download — but the browser-decodable formats this
     * method covers are exactly the ones MediaTypePolicy serves inline, so a
     * verbatim copy would render in our origin with whatever payload the
     * re-encode exists to strip.
     *
     * Protected so a test can simulate a build without the decoder — gd_info()
     * is a global that cannot be faked from a test.
     */
    protected function canDecodeImage(string $mime): bool
    {
        if (! function_exists('gd_info')) {
            return false;
        }

        /** @var array<string, mixed> $gd */
        $gd = gd_info();

        $key = match ($mime) {
            'image/jpeg' => 'JPEG Support',
            'image/png' => 'PNG Support',
            'image/gif' => 'GIF Read Support',
            'image/webp' => 'WebP Support',
            'image/avif' => 'AVIF Support',
            default => null,
        };

        return $key !== null && ($gd[$key] ?? false) === true;
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
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/tiff' => 'tif',
        'image/bmp' => 'bmp',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/rtf' => 'rtf',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'application/x-7z-compressed' => '7z',
        'application/vnd.rar' => 'rar',
        'application/gzip' => 'gz',
        'application/x-tar' => 'tar',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        'audio/mp4' => 'm4a',
        'audio/flac' => 'flac',
        'audio/aac' => 'aac',
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

        // 3b. Pixel-footprint guard for EVERY raster, not just the re-encoded
        // ones — a verbatim-stored TIFF can declare a pixel flood too, and
        // getimagesize() only reads the header (formats it cannot parse are
        // skipped, not refused; they are never decoded server-side).
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $this->guardPixelFootprint($sourcePath);
        }

        // 4. Process
        $reEncodes = in_array($mime, self::IMAGE_MIMES, true);

        // A browser-decodable raster this build cannot re-encode is refused,
        // not stored verbatim: these are the formats served inline, and the
        // re-encode is what strips whatever a crafted file smuggles in.
        if ($reEncodes && ! $this->canDecodeImage($mime)) {
            throw new MediaIngestException(
                "This server's image driver cannot decode {$mime}, so the upload cannot be "
                .'re-encoded for safe inline display. Install GD or Imagick support for this '
                .'format, or convert the image before uploading.'
            );
        }

        [$content, $width, $height] = match (true) {
            $reEncodes => $this->processImage($sourcePath, $mime),
            $mime === 'image/svg+xml' => $this->processSvg($sourcePath),
            str_starts_with($mime, 'image/') => $this->readRawImage($sourcePath),
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

        // 7. Dispatch conversions for raster images (SVG/PDF do not get presets).
        // Only for the ones actually decoded above: a preset job on a format the
        // driver cannot open is a queued failure per upload, forever.
        if ($reEncodes) {
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

    /** Dimension guard BEFORE any decode — see MAX_IMAGE_PIXELS above. */
    private function guardPixelFootprint(string $sourcePath): void
    {
        $dimensions = @getimagesize($sourcePath);

        if (is_array($dimensions) && $dimensions[0] > 0 && $dimensions[1] > 0
            && $dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS
        ) {
            throw new MediaIngestException(
                "Image dimensions ({$dimensions[0]}x{$dimensions[1]}) exceed the ".number_format(self::MAX_IMAGE_PIXELS).'-pixel limit.',
            );
        }
    }

    /**
     * Re-encode a raster image to strip EXIF, embedded scripts, and ICC payloads.
     * Creating a brand-new image from raw pixel data guarantees no metadata survives.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function processImage(string $sourcePath, string $mime): array
    {
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

    /**
     * A raster stored verbatim (HEIC, TIFF, BMP — formats GD cannot
     * re-encode) never goes through the metadata-stripping re-encode, and a
     * phone photo's EXIF carries GPS coordinates this CMS then republishes.
     * When a stripper is available the metadata is removed in place; when it
     * is not, the bytes are kept as-is — they are only ever served as an
     * attachment (MediaTypePolicy), so this is a privacy improvement layered
     * on an already-safe serve path, not a gate.
     *
     * @return array{0: string, 1: null, 2: null}
     */
    private function readRawImage(string $sourcePath): array
    {
        [$content, $width, $height] = $this->readRaw($sourcePath);

        return [$this->stripMetadata($content) ?? $content, $width, $height];
    }

    /**
     * Strip embedded metadata from an image container without re-encoding
     * its pixels. Returns null when no stripper is available or the format
     * cannot be parsed — the caller keeps the original bytes. Protected so a
     * test can pin both outcomes without an Imagick build on the runner.
     */
    protected function stripMetadata(string $content): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $image = new \Imagick;
            $image->readImageBlob($content);
            $image->stripImage();
            $stripped = $image->getImagesBlob();
            $image->clear();

            return $stripped;
        } catch (\Throwable) {
            return null;
        }
    }
}

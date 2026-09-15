<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Magna\Media\ConversionPreset;
use Magna\Media\ConversionPresetRegistry;
use Magna\Media\Exceptions\MediaIngestException;
use Magna\Media\Exceptions\MimeTypeNotAllowedException;
use Magna\Media\Jobs\ProcessMediaConversionJob;
use Magna\Media\Media;
use Magna\Media\MediaConversion;
use Magna\Media\MediaIngestor;
use Magna\Media\MediaUrlResolver;
use Magna\Media\MediaViewObject;
use Magna\Settings\MediaSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Create a minimal 10×10 JPEG in memory using PHP GD. */
function mediaTestJpeg(): string
{
    $gd = imagecreatetruecolor(10, 10);
    if ($gd === false) {
        throw new RuntimeException('GD not available.');
    }
    $blue = imagecolorallocate($gd, 50, 100, 200);
    if ($blue !== false) {
        imagefill($gd, 0, 0, $blue);
    }
    ob_start();
    imagejpeg($gd, null, 90);
    $data = ob_get_clean() ?? '';
    imagedestroy($gd);

    return $data;
}

/**
 * Inject a fake APP1 (EXIF) segment into a JPEG.
 * GD ignores APP segments when decoding pixels, so the image remains readable.
 * After Intervention/Image re-encodes from pixels, APP1 is gone.
 */
function mediaTestJpegWithExif(): string
{
    $base = mediaTestJpeg();

    // Fake EXIF: APP1 marker (0xFFE1) + big-endian length (2 bytes) + payload
    $payload = "Exif\x00\x00".str_repeat("\x00", 40); // 46 bytes of stub EXIF
    $segmentLength = strlen($payload) + 2;             // +2 for the length field itself
    $app1 = "\xFF\xE1".pack('n', $segmentLength).$payload;

    // Insert after the SOI marker (first 2 bytes 0xFF 0xD8)
    return "\xFF\xD8".$app1.substr($base, 2);
}

/** Write $content to a temp file and return the path. Caller must unlink. */
function mediaWriteTemp(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'mgtest_');
    if ($path === false) {
        throw new RuntimeException('Cannot allocate temp file.');
    }
    file_put_contents($path, $content);

    return $path;
}

// ── Security: malicious file rejection ───────────────────────────────────────

it('rejects a PHP file disguised as a JPEG', function (): void {
    Storage::fake('public');

    $path = mediaWriteTemp('<?php echo "pwned"; ?>');

    try {
        expect(fn () => app(MediaIngestor::class)->ingest($path, 'evil.jpg'))
            ->toThrow(MimeTypeNotAllowedException::class);
    } finally {
        unlink($path);
    }
});

it('MimeTypeNotAllowedException carries the detected MIME and filename', function (): void {
    Storage::fake('public');

    $path = mediaWriteTemp('<?php echo 1; ?>');
    $ex = null;

    try {
        app(MediaIngestor::class)->ingest($path, 'shell.jpg');
    } catch (MimeTypeNotAllowedException $e) {
        $ex = $e;
    } finally {
        unlink($path);
    }

    expect($ex)->not->toBeNull()
        ->and($ex->filename)->toBe('shell.jpg');
});

// ── Security: EXIF stripping ─────────────────────────────────────────────────

it('strips EXIF from ingested images by re-encoding from raw pixels', function (): void {
    Storage::fake('public');

    $jpegWithExif = mediaTestJpegWithExif();

    // Verify we actually have an APP1 marker in the input
    expect(substr_count($jpegWithExif, "\xFF\xE1"))->toBeGreaterThan(0);

    $path = mediaWriteTemp($jpegWithExif);

    try {
        $media = app(MediaIngestor::class)->ingest($path, 'photo-with-exif.jpg');
    } finally {
        unlink($path);
    }

    $stored = Storage::disk('public')->get($media->path);
    expect($stored)->not->toBeNull();

    // After Intervention/Image re-encodes, no EXIF APP1 marker survives
    expect(substr_count((string) $stored, "\xFF\xE1"))->toBe(0);
});

// ── Successful ingest: JPEG ───────────────────────────────────────────────────

it('ingests a valid JPEG and creates a Media record', function (): void {
    Storage::fake('public');
    Queue::fake();

    $path = mediaWriteTemp(mediaTestJpeg());

    try {
        $media = app(MediaIngestor::class)->ingest($path, 'photo.jpg', alt: 'A blue square');
    } finally {
        unlink($path);
    }

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->alt)->toBe('A blue square')
        ->and($media->original_filename)->toBe('photo.jpg')
        ->and($media->disk)->toBe('public')
        ->and($media->width)->toBe(10)
        ->and($media->height)->toBe(10);

    expect(Storage::disk('public')->exists($media->path))->toBeTrue();
    expect(Media::find($media->id))->not->toBeNull();
});

// ── Security: SVG sanitization ────────────────────────────────────────────────

it('sanitizes SVG by removing script tags', function (): void {
    Storage::fake('public');
    Queue::fake();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><rect width="10" height="10" fill="red"/></svg>';
    $path = mediaWriteTemp($svg);

    try {
        $media = app(MediaIngestor::class)->ingest($path, 'icon.svg');
    } finally {
        unlink($path);
    }

    $stored = (string) Storage::disk('public')->get($media->path);

    expect($stored)->not->toContain('<script>')
        ->and($stored)->toContain('<rect');
});

// Stage 9 regression: CSS-style url('...') references in presentation
// attributes (fill, stroke, filter, mask, etc.) were previously preserved
// verbatim — a staff member opening a downloaded SVG locally would fire a
// beacon request to an attacker-chosen URL. Note: this specifically covers
// the url('...') pattern the library's removeRemoteReferences targets — a
// plain <image xlink:href="https://..."> external reference is NOT
// stripped (legitimate SVG use), see the comment in MediaIngestor::processSvg().
it('strips CSS-style url() remote references from SVG presentation attributes', function (): void {
    Storage::fake('public');
    Queue::fake();

    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\"><rect fill=\"url('https://attacker.example.com/x.png')\" width=\"10\" height=\"10\"/></svg>";
    $path = mediaWriteTemp($svg);

    try {
        $media = app(MediaIngestor::class)->ingest($path, 'icon.svg');
    } finally {
        unlink($path);
    }

    $stored = (string) Storage::disk('public')->get($media->path);

    expect($stored)->not->toContain('attacker.example.com')
        ->and($stored)->toContain('<rect');
});

// Stage 9 regression: a file that content-sniffs as a supported image MIME
// but isn't actually well-formed (polyglot, truncated, corrupt) previously
// threw a raw decoder exception straight out of ingest() (500) instead of
// the intended MediaIngestException (clean validation error).
it('rejects a malformed image that content-sniffs as a supported MIME with a clean MediaIngestException', function (): void {
    Storage::fake('public');
    Queue::fake();

    // Valid GIF87a magic bytes followed by garbage — sniffs as image/gif,
    // but isn't a decodable GIF.
    $polyglot = 'GIF87a'.str_repeat("\x00", 20).'<?php system($_GET["c"]); ?>';
    $path = mediaWriteTemp($polyglot);

    try {
        expect(fn () => app(MediaIngestor::class)->ingest($path, 'evil.gif'))
            ->toThrow(MediaIngestException::class);
    } finally {
        unlink($path);
    }

    Storage::disk('public')->assertDirectoryEmpty('media');
});

// Stage 9 regression: a compressed file's byte size says nothing about its
// decoded pixel footprint — a small, cleverly-compressed image declaring an
// enormous canvas can exhaust memory the moment something decodes it.
function pngWithDeclaredDimensions(int $width, int $height): string
{
    $sig = "\x89PNG\r\n\x1a\n";
    $ihdrData = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $ihdrCrc = crc32('IHDR'.$ihdrData);
    $ihdrChunk = pack('N', strlen($ihdrData)).'IHDR'.$ihdrData.pack('N', $ihdrCrc);
    $iendChunk = pack('N', 0).'IEND'.pack('N', crc32('IEND'));

    return $sig.$ihdrChunk.$iendChunk;
}

it('rejects an image whose declared pixel dimensions exceed the limit, before decoding it', function (): void {
    Storage::fake('public');
    Queue::fake();

    $path = mediaWriteTemp(pngWithDeclaredDimensions(20_000, 20_000)); // 400M pixels

    try {
        expect(fn () => app(MediaIngestor::class)->ingest($path, 'huge.png'))
            ->toThrow(MediaIngestException::class, 'exceed the');
    } finally {
        unlink($path);
    }

    Storage::disk('public')->assertDirectoryEmpty('media');
});

it('accepts an image within the pixel dimension limit', function (): void {
    Storage::fake('public');
    Queue::fake();

    $path = mediaWriteTemp(mediaTestJpeg());

    try {
        $media = app(MediaIngestor::class)->ingest($path, 'small.jpg');
    } finally {
        unlink($path);
    }

    expect($media)->not->toBeNull();
});

// ── Conversions: queued ───────────────────────────────────────────────────────

it('dispatches a conversion job per preset when a JPEG is ingested', function (): void {
    Storage::fake('public');
    Queue::fake();

    $path = mediaWriteTemp(mediaTestJpeg());

    try {
        app(MediaIngestor::class)->ingest($path, 'photo.jpg');
    } finally {
        unlink($path);
    }

    // Default registry has thumb, card, hero
    Queue::assertPushed(ProcessMediaConversionJob::class, 3);
});

it('does not dispatch conversion jobs for SVG uploads', function (): void {
    Storage::fake('public');
    Queue::fake();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
    $path = mediaWriteTemp($svg);

    try {
        app(MediaIngestor::class)->ingest($path, 'icon.svg');
    } finally {
        unlink($path);
    }

    Queue::assertNotPushed(ProcessMediaConversionJob::class);
});

// ── Conversions: produce variants ────────────────────────────────────────────

it('conversion job generates WebP variant and persists a MediaConversion record', function (): void {
    Storage::fake('public');

    // Store a real JPEG in fake storage
    $jpegData = mediaTestJpeg();
    $media = Media::factory()->create(['disk' => 'public', 'mime_type' => 'image/jpeg', 'path' => 'media/test/source.jpg']);
    Storage::disk('public')->put($media->path, $jpegData);

    // Register a simple test preset
    $registry = app(ConversionPresetRegistry::class);
    $registry->register(new ConversionPreset('minitest', 5, 5, fit: true, generateWebP: true, generateAvif: false));

    // Run the job synchronously
    (new ProcessMediaConversionJob($media->id, 'minitest'))->handle($registry);

    $conversion = MediaConversion::where('media_id', $media->id)
        ->where('preset', 'minitest')
        ->where('format', 'webp')
        ->first();

    expect($conversion)->not->toBeNull()
        ->and($conversion->width)->toBe(5)
        ->and($conversion->height)->toBe(5)
        ->and(Storage::disk('public')->exists($conversion->path))->toBeTrue();
});

// ── Signed URLs ───────────────────────────────────────────────────────────────

it('signed URLs for non-S3 disks contain expiry and signature parameters', function (): void {
    $media = Media::factory()->create(['disk' => 'public']);

    $url = app(MediaUrlResolver::class)->signedUrl($media, expiresAt: now()->addMinutes(30));

    expect($url)->toContain('signature=')
        ->and($url)->toContain('expires=');
});

it('signed URL expires: a URL generated with a past expiry is invalid', function (): void {
    $media = Media::factory()->create(['disk' => 'public']);

    $url = URL::temporarySignedRoute(
        'magna.media.serve',
        now()->subSecond(),
        ['media' => $media->id],
    );

    expect(URL::hasValidSignature(request()->create($url)))->toBeFalse();
});

// ── Delete policy: soft delete ────────────────────────────────────────────────

it('deleting media soft-deletes the record — entries retain the ULID reference', function (): void {
    $media = Media::factory()->create();
    $id = $media->id;

    $media->delete();

    // Hard query: still in DB
    expect(Media::withTrashed()->find($id))->not->toBeNull();
    // Normal scope: excluded
    expect(Media::find($id))->toBeNull();
    // trashed() helper
    expect(Media::withTrashed()->find($id)->trashed())->toBeTrue();
});

// ── MediaViewObject ───────────────────────────────────────────────────────────

it('MediaViewObject::fromModel exposes metadata and delegates URL resolution', function (): void {
    Storage::fake('public');

    $media = Media::factory()->create([
        'original_filename' => 'hero.jpg',
        'alt' => 'Hero image',
        'width' => 1920,
        'height' => 1080,
        'size' => 512_000,
    ]);

    Storage::disk('public')->put($media->path, 'fake');

    $obj = MediaViewObject::fromModel($media, app(MediaUrlResolver::class));

    expect($obj->originalFilename)->toBe('hero.jpg')
        ->and($obj->alt)->toBe('Hero image')
        ->and($obj->width)->toBe(1920)
        ->and($obj->height)->toBe(1080)
        ->and($obj->url())->toBeString()->not->toBeEmpty();
});

// ── magna:media:reconvert command ─────────────────────────────────────────────

it('magna:media:reconvert queues jobs for all image media', function (): void {
    Queue::fake();

    Media::factory()->count(2)->create(['mime_type' => 'image/jpeg']);
    Media::factory()->create(['mime_type' => 'image/svg+xml']); // should be excluded

    $this->artisan('magna:media:reconvert')->assertSuccessful();

    // 2 image records × 3 default presets (thumb, card, hero) = 6 jobs
    Queue::assertPushed(ProcessMediaConversionJob::class, 6);
});

/*
|--------------------------------------------------------------------------
| The library holds what a library is asked to hold
|--------------------------------------------------------------------------
|
| Roya's staff could not put a phone photo, a slide deck or a CSV into a
| collection: the ingestor refused everything outside a short allowlist, and the
| collection controller turned that refusal into "That kind of file cannot be
| uploaded here" against the file field. The allowlist is still an allowlist —
| what stays out is anything a browser or a host can execute.
*/

it('accepts the document and media types an office actually exchanges', function (string $mime, string $bytes): void {
    Storage::fake('public');

    $path = tempnam(sys_get_temp_dir(), 'ingest');
    file_put_contents($path, $bytes);

    $media = app(MediaIngestor::class)->ingest($path, 'attachment');

    expect($media->mime_type)->toBe($mime);

    // Stored verbatim: nothing here is a raster this build can decode, so
    // re-encoding it would be a failure rather than a safeguard.
    expect(Storage::disk('public')->get($media->path))->toBe($bytes);

    @unlink($path);
})->with([
    'plain text' => ['text/plain', 'a note
'],
    'csv' => ['text/csv', 'one,two
1,2
'],
]);

it('still refuses what a browser or a host can execute', function (): void {
    Storage::fake('public');

    $path = tempnam(sys_get_temp_dir(), 'ingest');
    file_put_contents($path, '<?php echo "owned";');

    expect(fn () => app(MediaIngestor::class)->ingest($path, 'payload.php'))
        ->toThrow(MimeTypeNotAllowedException::class);

    @unlink($path);
});

it('sizes a document against the document ceiling, not the image one', function (): void {
    // The two ceilings ship different defaults, and every non-image type used to
    // fall through a match arm to the image one — so a type added to the
    // allowlist and not to that list silently inherited the smaller limit.
    $settings = MediaSettings::get();
    $settings->max_image_upload_bytes = 1_048_576;
    $settings->max_document_upload_bytes = 8_388_608;
    $settings->save();

    Storage::fake('public');

    $path = tempnam(sys_get_temp_dir(), 'ingest');
    file_put_contents($path, str_repeat('x', 2_000_000).'
');

    $media = app(MediaIngestor::class)->ingest($path, 'big.txt');

    expect($media->mime_type)->toBe('text/plain');

    @unlink($path);
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;
use Tests\TestCase;

// Feature/Media declares its base class per file — see tests/Pest.php.
uses(TestCase::class, RefreshDatabase::class);

/**
 * `/_media/pub/{media}` has no signature and no auth — it exists solely so
 * public-disk media that must not render inline (SVG, and every type the
 * ingestor stores verbatim — see MediaTypePolicy) is always served with
 * Content-Disposition: attachment. Nothing but the controller enforces that
 * narrow contract, and the failure mode if it stops doing so is that every
 * private-disk file becomes readable by anyone holding an ID.
 */
function mediaRow(array $overrides = []): Media
{
    return Media::factory()->create(array_merge([
        'disk' => 'public',
        'mime_type' => 'image/svg+xml',
        'path' => 'media/example.svg',
        'original_filename' => 'example.svg',
    ], $overrides));
}

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

it('serves a public-disk SVG as an attachment on the unsigned route', function (): void {
    Storage::disk('public')->put('media/example.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $media = mediaRow();

    $this->get(route('magna.media.serve.public', ['media' => $media->id]))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertDownload('example.svg');
});

it('refuses to serve a private-disk file on the unsigned route', function (): void {
    Storage::disk('local')->put('media/secret.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $media = mediaRow(['disk' => 'local', 'path' => 'media/secret.svg']);

    // A ULID is not an access control — the signed route is the only way in.
    $this->get(route('magna.media.serve.public', ['media' => $media->id]))
        ->assertNotFound();
});

it('refuses to serve an inline-safe type on the unsigned route', function (): void {
    Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');

    $media = mediaRow([
        'mime_type' => 'image/jpeg',
        'path' => 'media/photo.jpg',
        'original_filename' => 'photo.jpg',
    ]);

    // Inline-safe media keeps its raw disk URL and never needs this route —
    // anything reaching it with such a type is probing, not the resolver.
    $this->get(route('magna.media.serve.public', ['media' => $media->id]))
        ->assertNotFound();
});

/*
 * Regression: publicUrl() used to route ONLY SVG through the serve
 * controller — every other type got a raw disk URL, i.e. the web server
 * streaming bytes with no nosniff and no attachment header. For the types
 * the ingestor stores verbatim (HEIC, text, archives...) those headers are
 * the entire safety story.
 */
it('serves a verbatim-stored type as an attachment on the unsigned route', function (string $mime, string $path, string $filename, string $bytes): void {
    Storage::disk('public')->put($path, $bytes);

    $media = mediaRow([
        'mime_type' => $mime,
        'path' => $path,
        'original_filename' => $filename,
    ]);

    $this->get(route('magna.media.serve.public', ['media' => $media->id]))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertDownload($filename);
})->with([
    'plain text' => ['text/plain', 'media/notes.txt', 'notes.txt', '<html><script>alert(1)</script>'],
    'HEIC photo' => ['image/heic', 'media/photo.heic', 'photo.heic', 'heic-bytes'],
]);

it('keeps a hostile original filename on one header line', function (): void {
    // original_filename is user-supplied. addslashes() left CR/LF intact —
    // PHP's header() blocks those, but Octane's runtimes do not go through
    // header(), so the disposition is now built by Symfony's HeaderUtils,
    // which percent-encodes the real name and quotes an ASCII fallback.
    Storage::disk('public')->put('media/notes.txt', 'plain');

    $media = mediaRow([
        'mime_type' => 'text/plain',
        'path' => 'media/notes.txt',
        'original_filename' => "evil\r\nX-Injected: 1.txt",
    ]);

    $response = $this->get(route('magna.media.serve.public', ['media' => $media->id]));
    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('attachment')
        ->and($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n");
});

it('resolves a public URL through the serve controller for non-inline-safe types only', function (): void {
    $text = mediaRow([
        'mime_type' => 'text/plain',
        'path' => 'media/notes.txt',
        'original_filename' => 'notes.txt',
    ]);
    $image = mediaRow([
        'mime_type' => 'image/png',
        'path' => 'media/photo.png',
        'original_filename' => 'photo.png',
    ]);

    $resolver = app(MediaUrlResolver::class);

    expect($resolver->publicUrl($text))->toBe(route('magna.media.serve.public', ['media' => $text->id]))
        ->and($resolver->publicUrl($image))->toBe(Storage::disk('public')->url('media/photo.png'));
});

it('sends nosniff on ordinary signed delivery too', function (): void {
    Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');

    $media = mediaRow([
        'mime_type' => 'image/jpeg',
        'path' => 'media/photo.jpg',
        'original_filename' => 'photo.jpg',
    ]);

    $this->get(URL::temporarySignedRoute('magna.media.serve', now()->addHour(), ['media' => $media->id]))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
});

it('404s a signed URL asking for a preset that has no conversion', function (): void {
    Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');

    $media = mediaRow([
        'mime_type' => 'image/jpeg',
        'path' => 'media/photo.jpg',
        'original_filename' => 'photo.jpg',
    ]);

    // Silently falling back to the original would stream the full-size file
    // to a request that asked for a thumbnail.
    $this->get(URL::temporarySignedRoute('magna.media.serve', now()->addHour(), [
        'media' => $media->id,
        'preset' => 'thumb',
    ]))->assertNotFound();
});

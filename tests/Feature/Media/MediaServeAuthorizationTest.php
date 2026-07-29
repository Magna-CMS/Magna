<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Magna\Media\Media;
use Tests\TestCase;

// Feature/Media declares its base class per file — see tests/Pest.php.
uses(TestCase::class, RefreshDatabase::class);

/**
 * `/_media/pub/{media}` has no signature and no auth — it exists solely so a
 * public-disk SVG is always served with Content-Disposition: attachment.
 * Nothing but the controller enforces that narrow contract, and the failure
 * mode if it stops doing so is that every private-disk file becomes readable
 * by anyone holding an ID.
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

it('refuses to serve a non-SVG on the unsigned route', function (): void {
    Storage::disk('public')->put('media/photo.jpg', 'jpeg-bytes');

    $media = mediaRow([
        'mime_type' => 'image/jpeg',
        'path' => 'media/photo.jpg',
        'original_filename' => 'photo.jpg',
    ]);

    $this->get(route('magna.media.serve.public', ['media' => $media->id]))
        ->assertNotFound();
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

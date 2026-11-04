<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The second half of what the root forwarder promises. It sends
 * /storage/logs/laravel.log to the front controller once public/storage holds
 * no such file, and Laravel has a route of its own at /storage/{path}: the
 * `local` disk's `serve`, which answers an unsigned request 404 in production
 * and 403 anywhere else. Its root is storage/app/private, never storage/.
 */
function asProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');

    // The route captures the environment when it registers, so register it again.
    (new FilesystemServiceProvider(app()))->boot();
}

it('answers 404 for private storage in production, with the file really on disk', function (string $file, string $url): void {
    asProduction();

    $path = storage_path($file);
    $contents = 'magna-private-'.bin2hex(random_bytes(6));

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);

    try {
        $response = $this->get($url);

        $response->assertNotFound();
        expect((string) $response->getContent())->not->toContain($contents);
    } finally {
        @unlink($path);
    }
})->with([
    'a log' => ['logs/forwarder-proof.log', '/storage/logs/forwarder-proof.log'],
    'the local disk, unsigned' => ['app/private/forwarder-proof.txt', '/storage/forwarder-proof.txt'],
    'a full path into the private disk' => ['app/private/forwarder-proof.txt', '/storage/app/private/forwarder-proof.txt'],
]);

/**
 * What public/storage points at is the whole boundary. A link to storage/
 * itself would put logs and sessions on a URL, forwarder or not.
 */
it('links public/storage to the public disk and nothing wider', function (): void {
    expect(config('filesystems.links'))->toBe([public_path('storage') => storage_path('app/public')])
        ->and(config('filesystems.disks.public.root'))->toBe(storage_path('app/public'))
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});

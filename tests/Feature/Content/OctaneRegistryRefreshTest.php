<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Magna\Content\Http\Middleware\RefreshDatabaseContentTypes;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaRegistry;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Regression tests for Octane runtime consistency of the content-type
 * registry. They simulate the Octane condition — a persistent SchemaRegistry
 * singleton whose in-memory set was frozen at worker boot — by writing a
 * content_types row AFTER the app has booted (so the registry has not loaded
 * it) and asserting the per-request refresh makes it visible/invisible.
 */

function writeContentTypeRow(string $handle): ContentTypeRecord
{
    return ContentTypeRecord::create([
        'handle' => $handle,
        'display_name' => ucfirst($handle),
        'is_database_defined' => true,
        'schema' => [
            'handle' => $handle,
            'displayName' => ucfirst($handle),
            'localizable' => false,
            'draftable' => false,
            'fields' => [
                ['handle' => 'title', 'type' => 'text', 'required' => true],
            ],
        ],
    ]);
}

it('refreshDatabaseTypes picks up a content type created after worker boot', function (): void {
    $registry = app(SchemaRegistry::class);
    expect($registry->has('late_type'))->toBeFalse();

    // Another worker (or the admin builder) creates the type — the row write
    // invalidates the cached row set via ContentTypeRecord::booted().
    writeContentTypeRow('late_type');

    $registry->refreshDatabaseTypes();

    expect($registry->has('late_type'))->toBeTrue();
});

it('refreshDatabaseTypes drops a content type deleted after worker boot', function (): void {
    writeContentTypeRow('temp_type');
    $registry = app(SchemaRegistry::class);
    $registry->refreshDatabaseTypes();
    expect($registry->has('temp_type'))->toBeTrue();

    // Delete through the model instance so the cache-invalidation hook on
    // ContentTypeRecord::deleted() fires (the path the admin builder uses).
    ContentTypeRecord::query()->where('handle', 'temp_type')->firstOrFail()->delete();

    $registry->refreshDatabaseTypes();

    expect($registry->has('temp_type'))->toBeFalse();
});

it('the refresh middleware re-syncs content types when running under Octane', function (): void {
    $registry = app(SchemaRegistry::class);
    writeContentTypeRow('mw_type');
    expect($registry->has('mw_type'))->toBeFalse(); // created post-boot, not yet loaded

    putenv('LARAVEL_OCTANE=1');
    try {
        app(RefreshDatabaseContentTypes::class)->handle(
            Request::create('/api/v1/content/mw_type', 'GET'),
            fn (Request $r): Response => new Response('ok'),
        );
    } finally {
        putenv('LARAVEL_OCTANE');
    }

    expect($registry->has('mw_type'))->toBeTrue();
});

it('the refresh middleware is a no-op when not running under Octane', function (): void {
    $registry = app(SchemaRegistry::class);
    writeContentTypeRow('fpm_type');
    // Created after boot and not refreshed → not present. Under php-fpm the
    // real boot would have loaded it; here we prove the middleware itself does
    // nothing without the Octane flag (php-fpm relies on per-request boot,
    // not this middleware).
    expect($registry->has('fpm_type'))->toBeFalse();

    app(RefreshDatabaseContentTypes::class)->handle(
        Request::create('/', 'GET'),
        fn (Request $r): Response => new Response('ok'),
    );

    expect($registry->has('fpm_type'))->toBeFalse();
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Magna\Content\ContentType;
use Magna\Content\FieldTypeRegistry;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    // Clean up any schema files created during the test
    $path = base_path('schemas/test_article.json');
    if (file_exists($path)) {
        unlink($path);
    }
});

it('scaffolds a valid schema file at schemas/{handle}.json', function (): void {
    $path = base_path('schemas/test_article.json');
    expect(file_exists($path))->toBeFalse();

    $this->artisan('magna:type:make', ['handle' => 'test_article'])
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $raw = json_decode(file_get_contents($path), true);
    expect($raw)->toBeArray()
        ->and($raw['handle'])->toBe('test_article')
        ->and($raw['displayName'])->toBe('Test Article')  // title-case of test_article
        ->and($raw['localizable'])->toBeFalse()
        ->and($raw['draftable'])->toBeFalse()
        ->and($raw['fields'])->toBeArray()->toHaveCount(1)
        ->and($raw['fields'][0]['handle'])->toBe('title')
        ->and($raw['fields'][0]['type'])->toBe('text');

    // Must be loadable by ContentType (via FieldTypeRegistry)
    $type = ContentType::fromArray($raw, app(FieldTypeRegistry::class));
    expect($type->handle)->toBe('test_article');
});

it('respects --displayName, --localizable, and --draftable flags', function (): void {
    $path = base_path('schemas/test_article.json');

    $this->artisan('magna:type:make', [
        'handle' => 'test_article',
        '--displayName' => 'My Article',
        '--localizable' => true,
        '--draftable' => true,
    ])->assertExitCode(0);

    $raw = json_decode(file_get_contents($path), true);
    expect($raw['displayName'])->toBe('My Article')
        ->and($raw['localizable'])->toBeTrue()
        ->and($raw['draftable'])->toBeTrue();
});

it('exits with an error when the schema file already exists', function (): void {
    $path = base_path('schemas/test_article.json');
    file_put_contents($path, '{}');

    $this->artisan('magna:type:make', ['handle' => 'test_article'])
        ->assertExitCode(1);
});

it('rejects a handle with hyphens or uppercase', function (): void {
    $this->artisan('magna:type:make', ['handle' => 'Blog-Post'])
        ->assertExitCode(1);
});

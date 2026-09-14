<?php

declare(strict_types=1);

use Magna\Content\ContentType;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\OpenApiGenerator;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Byte-for-byte pin of the generated OpenAPI documents (W3-5).
 *
 * The spec is a public surface: third-party tooling consumes it verbatim.
 * The generator's internals are being decomposed (ResponseShapes +
 * per-resource builders), and these snapshots prove the refactor changes
 * nothing a consumer can observe. On a mismatch, either the change is an
 * intended spec change — delete the fixture, re-run once to regenerate,
 * review the diff and commit it with the change that caused it — or the
 * refactor broke the document. Never loosen the comparison.
 *
 * The registry is built fresh (not the app singleton) so the snapshot never
 * depends on what other tests or plugins happen to have registered. The
 * article type deliberately carries one field of every core field type, so
 * every branch of the field-to-schema mapping is pinned.
 */

function openApiSnapshotGenerator(): OpenApiGenerator
{
    /** @var FieldTypeRegistry $fieldTypes */
    $fieldTypes = app(FieldTypeRegistry::class);
    $registry = new SchemaRegistry($fieldTypes);

    $registry->register(ContentType::fromArray([
        'handle' => 'article',
        'displayName' => 'Article',
        'localizable' => true,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'summary', 'type' => 'textarea'],
            ['handle' => 'body', 'type' => 'richtext'],
            ['handle' => 'notes', 'type' => 'markdown'],
            ['handle' => 'reading_minutes', 'type' => 'number'],
            ['handle' => 'featured', 'type' => 'boolean'],
            ['handle' => 'published_on', 'type' => 'date'],
            ['handle' => 'reviewed_at', 'type' => 'datetime'],
            ['handle' => 'category', 'type' => 'select'],
            ['handle' => 'hero', 'type' => 'media'],
            ['handle' => 'related', 'type' => 'relation'],
            ['handle' => 'layout', 'type' => 'blocks'],
            ['handle' => 'metadata', 'type' => 'json'],
            ['handle' => 'slug', 'type' => 'slug'],
            ['handle' => 'contact_email', 'type' => 'email'],
            ['handle' => 'canonical', 'type' => 'url'],
            ['handle' => 'accent', 'type' => 'color'],
        ],
    ], $fieldTypes));

    $registry->register(ContentType::fromArray([
        'handle' => 'landing_page',
        'displayName' => 'Landing page',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
        ],
    ], $fieldTypes));

    return new OpenApiGenerator($registry);
}

/**
 * @param  array<string, mixed>  $document
 */
function assertOpenApiSnapshot(array $document, string $name): void
{
    $fixture = base_path('tests/Fixtures/openapi/'.$name.'.json');

    $json = json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    )."\n";

    if (! is_file($fixture)) {
        @mkdir(dirname($fixture), recursive: true);
        file_put_contents($fixture, $json);
        test()->fail(
            "Snapshot fixture tests/Fixtures/openapi/{$name}.json was missing and has been written. ".
            'Review it and commit it alongside the change that produced it.',
        );
    }

    expect($json)->toBe((string) file_get_contents($fixture));
}

it('the delivery OpenAPI document is byte-for-byte stable', function (): void {
    assertOpenApiSnapshot(openApiSnapshotGenerator()->generate(), 'delivery-spec');
});

it('the full (delivery + management) OpenAPI document is byte-for-byte stable', function (): void {
    assertOpenApiSnapshot(openApiSnapshotGenerator()->generateFull(), 'full-spec');
});

<?php

declare(strict_types=1);

namespace Magna\Delivery\OpenApi;

use Magna\Content\ContentType;
use Magna\Content\EntryStatus;
use Magna\Content\Field;

/**
 * The Delivery API's contribution to the OpenAPI document: per-type read
 * paths and the JSON-schema components derived from each type's fields.
 * The emitted document is pinned byte-for-byte by OpenApiSnapshotTest.
 */
final class DeliveryPaths
{
    /**
     * List, single-entry and preview-token paths for one content type.
     *
     * @return array<string, mixed>
     */
    public function typePaths(string $handle, ContentType $type): array
    {
        $basePath = '/api/v1/content/'.$handle;

        return [
            $basePath => [
                'get' => [
                    'operationId' => 'list_'.$handle,
                    'summary' => 'List '.$type->displayName.' entries',
                    'security' => ResponseShapes::bearer(),
                    'parameters' => $this->listParameters(),
                    'responses' => [
                        '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$handle.'List']]]],
                        '304' => ['description' => 'Not Modified'],
                        '400' => ['description' => 'Bad Request'],
                        '401' => ['description' => 'Unauthorized'],
                        '404' => ['description' => 'Unknown content type'],
                        '429' => ['description' => 'Rate limit exceeded'],
                    ],
                ],
            ],
            $basePath.'/{id}' => [
                'get' => [
                    'operationId' => 'get_'.$handle,
                    'summary' => 'Get a single '.$type->displayName.' entry',
                    'security' => ResponseShapes::bearer(),
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Entry ULID or slug'],
                        ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'with', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'preview', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                        ['name' => 'preview_token', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'resolve', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]], 'description' => 'Attach _resolved data (entry lists, sanitized richtext, menus) to every block in blocks fields'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '304' => ['description' => 'Not Modified'],
                        '401' => ['description' => 'Unauthorized'],
                        '403' => ['description' => 'Invalid preview token'],
                        '404' => ['description' => 'Not found'],
                        '429' => ['description' => 'Rate limit exceeded'],
                    ],
                ],
            ],
            $basePath.'/{id}/preview-token' => [
                'post' => [
                    'operationId' => 'mint_preview_token_'.$handle,
                    'summary' => 'Mint a short-lived preview token for a '.$type->displayName.' entry',
                    'security' => ResponseShapes::bearer(),
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => ResponseShapes::optionalJsonBody([
                        'type' => 'object',
                        'properties' => ['ttl_seconds' => ['type' => 'integer', 'default' => 3600]],
                    ]),
                    'responses' => [
                        '200' => ['description' => 'Token minted'],
                        '401' => ['description' => 'Unauthorized — management token required'],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The entry schema and its list wrapper for one content type.
     *
     * @return array<string, mixed>
     */
    public function typeSchemas(string $handle, ContentType $type): array
    {
        /** @var array<string, mixed> $properties */
        $properties = [
            'id' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => [$handle]],
            'status' => ['type' => 'string', 'enum' => array_map(fn (EntryStatus $s): string => $s->value, EntryStatus::cases())],
            'locale' => ['type' => 'string', 'nullable' => true],
            'published_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        ];

        foreach ($type->fields as $field) {
            $properties[$field->handle] = $this->fieldToSchema($field);
        }

        return [
            $handle => ['type' => 'object', 'properties' => $properties],
            $handle.'List' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$handle]],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                    'included' => ['type' => 'object'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paginationMetaSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'next_cursor' => ['type' => 'string', 'nullable' => true],
                'has_more' => ['type' => 'boolean'],
                'per_page' => ['type' => 'integer'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function listParameters(): array
    {
        return [
            ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Opaque cursor from previous page meta.next_cursor'],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25, 'maximum' => 100]],
            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Field to sort by. Prefix with - for descending (e.g. -published_at)'],
            ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated list of field handles to include'],
            ['name' => 'with', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated relation field handles to populate'],
            ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'object'], 'description' => 'Filters: filter[field][op]=value'],
            ['name' => 'preview', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
            ['name' => 'preview_token', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'resolve', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]], 'description' => 'Attach _resolved data (entry lists, sanitized richtext, menus) to every block in blocks fields'],
        ];
    }

    /** @return array<string, mixed> */
    private function fieldToSchema(Field $field): array
    {
        $nullable = ! $field->required;

        return match ($field->type->typeName()) {
            'text', 'textarea', 'markdown', 'email', 'url', 'color', 'slug', 'select' => ['type' => 'string', 'nullable' => $nullable],
            'number' => ['type' => 'number', 'nullable' => $nullable],
            'boolean' => ['type' => 'boolean', 'nullable' => $nullable],
            'date', 'datetime' => ['type' => 'string', 'format' => 'date-time', 'nullable' => $nullable],
            'json', 'richtext', 'blocks' => ['type' => 'object', 'nullable' => $nullable],
            'media' => ['type' => 'object', 'nullable' => $nullable, 'description' => 'Resolved media object with url, srcset, width, height'],
            'relation' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Populated when requested via ?with='],
            default => ['type' => 'string'],
        };
    }
}

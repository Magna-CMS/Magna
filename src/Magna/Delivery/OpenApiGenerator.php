<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Magna\Content\ContentType;
use Magna\Content\EntryStatus;
use Magna\Content\Field;
use Magna\Content\SchemaRegistry;

/**
 * Generates an OpenAPI 3.1 specification from the registered content types.
 * Auto-updated whenever SchemaRegistry::register() is called.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly SchemaRegistry $schema,
    ) {}

    /**
     * Stage 13 (C3-05): previously every registered content type's field
     * handles/types were included in the generated spec regardless of the
     * calling token's actual content.{type}.* permissions — a management
     * token scoped to a single content type still saw the full internal
     * schema shape of every other type in the system. Filtered to only
     * types the caller holds at least view access to. Falls back to
     * showing everything when there's no authenticated user in scope
     * (e.g. generated offline via artisan, not through the HTTP route),
     * matching this class's existing behavior for non-HTTP callers.
     *
     * @return array<string, ContentType>
     */
    private function visibleTypes(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return $this->schema->all();
        }

        return array_filter(
            $this->schema->all(),
            fn (string $handle): bool => $user->can("content.{$handle}.view"),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Generate a merged OpenAPI 3.1 spec covering both the Delivery and Management APIs.
     *
     * @return array<string, mixed>
     */
    public function generateFull(): array
    {
        $delivery = $this->generate();
        $management = $this->generateManagement();

        $deliveryPaths = $delivery['paths'] ?? [];
        $mgmtPaths = $management['paths'] ?? [];
        $deliveryComponents = $delivery['components'] ?? [];
        $mgmtComponents = $management['components'] ?? [];

        $allPaths = array_merge(
            is_array($deliveryPaths) ? $deliveryPaths : [],
            is_array($mgmtPaths) ? $mgmtPaths : [],
        );

        $deliveryCompsArr = is_array($deliveryComponents) ? $deliveryComponents : [];
        $mgmtCompsArr = is_array($mgmtComponents) ? $mgmtComponents : [];

        $securitySchemes = $deliveryCompsArr['securitySchemes'] ?? [];
        $deliverySchemas = $deliveryCompsArr['schemas'] ?? [];
        $mgmtSchemas = $mgmtCompsArr['schemas'] ?? [];

        $allSchemas = array_merge(
            is_array($deliverySchemas) ? $deliverySchemas : [],
            is_array($mgmtSchemas) ? $mgmtSchemas : [],
        );

        return array_merge($delivery, [
            'info' => ['title' => 'Magna CMS API', 'version' => '1'],
            'paths' => $allPaths,
            'components' => [
                'securitySchemes' => is_array($securitySchemes) ? $securitySchemes : [],
                'schemas' => $allSchemas,
            ],
        ]);
    }

    /**
     * Generate OpenAPI paths for the Management API (/api/v1/manage/...).
     *
     * @return array<string, mixed>
     */
    public function generateManagement(): array
    {
        $paths = [];

        // ── Entries (per registered content type) ─────────────────────────────
        foreach ($this->visibleTypes() as $handle => $type) {
            $base = '/api/v1/manage/entries/'.$handle;

            $paths[$base] = [
                'get' => [
                    'operationId' => 'manage_list_'.$handle,
                    'summary' => 'List '.$type->displayName.' entries (management)',
                    'tags' => ['Management — Entries'],
                    'security' => [['BearerAuth' => []]],
                    'parameters' => [['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]]],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '401' => ['description' => 'Unauthorized'],
                        '403' => ['description' => 'Forbidden'],
                    ],
                ],
                'post' => [
                    'operationId' => 'manage_create_'.$handle,
                    'summary' => 'Create a '.$type->displayName.' entry',
                    'tags' => ['Management — Entries'],
                    'security' => [['BearerAuth' => []]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                    'responses' => [
                        '201' => ['description' => 'Created'],
                        '401' => ['description' => 'Unauthorized'],
                        '403' => ['description' => 'Forbidden'],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
            ];

            $entryPath = $base.'/{id}';
            $paths[$entryPath] = [
                'get' => ['operationId' => 'manage_get_'.$handle, 'summary' => 'Get a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
                'put' => ['operationId' => 'manage_update_'.$handle, 'summary' => 'Update a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found'], '422' => ['description' => 'Validation error']]],
                'delete' => ['operationId' => 'manage_delete_'.$handle, 'summary' => 'Delete a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['204' => ['description' => 'Deleted'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            ];

            $paths[$entryPath.'/publish'] = ['post' => ['operationId' => 'manage_publish_'.$handle, 'summary' => 'Publish a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['publish_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]]]]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]]];
            $paths[$entryPath.'/unpublish'] = ['post' => ['operationId' => 'manage_unpublish_'.$handle, 'summary' => 'Unpublish a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found'], '422' => ['description' => 'Entry not published']]]];
            $paths[$entryPath.'/draft'] = ['post' => ['operationId' => 'manage_draft_'.$handle, 'summary' => 'Create a draft of a published '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['201' => ['description' => 'Draft created'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found'], '422' => ['description' => 'Not a published entry']]]];
            $paths[$entryPath.'/revisions'] = ['get' => ['operationId' => 'manage_revisions_'.$handle, 'summary' => 'List revisions for a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]]];
            $paths[$entryPath.'/revisions/{revision}/restore'] = ['post' => ['operationId' => 'manage_restore_'.$handle, 'summary' => 'Restore a revision of a '.$type->displayName.' entry', 'tags' => ['Management — Entries'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'revision', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]]];
        }

        // ── Media ──────────────────────────────────────────────────────────────
        $paths['/api/v1/manage/media'] = [
            'post' => ['operationId' => 'manage_media_upload', 'summary' => 'Upload a media file', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'alt' => ['type' => 'string'], 'title' => ['type' => 'string'], 'folder_id' => ['type' => 'string']], 'required' => ['file']]]]], 'responses' => ['201' => ['description' => 'Uploaded'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '422' => ['description' => 'Validation error']]],
        ];
        $paths['/api/v1/manage/media/{media}'] = [
            'get' => ['operationId' => 'manage_media_show', 'summary' => 'Get a media item', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'media', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            'delete' => ['operationId' => 'manage_media_delete', 'summary' => 'Delete a media item', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'media', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['204' => ['description' => 'Deleted'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];
        $paths['/api/v1/manage/media/folders'] = [
            'get' => ['operationId' => 'manage_folders_list', 'summary' => 'List media folders', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
            'post' => ['operationId' => 'manage_folders_create', 'summary' => 'Create a media folder', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'parent_id' => ['type' => 'string', 'nullable' => true]], 'required' => ['name']]]]], 'responses' => ['201' => ['description' => 'Created'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '422' => ['description' => 'Validation error']]],
        ];
        $paths['/api/v1/manage/media/folders/{folder}'] = [
            'delete' => ['operationId' => 'manage_folders_delete', 'summary' => 'Delete a media folder', 'tags' => ['Management — Media'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'folder', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['204' => ['description' => 'Deleted'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];

        // ── Content types ──────────────────────────────────────────────────────
        $paths['/api/v1/manage/content-types'] = [
            'get' => ['operationId' => 'manage_content_types_list', 'summary' => 'List registered content types', 'tags' => ['Management — Content Types'], 'security' => [['BearerAuth' => []]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
            'post' => ['operationId' => 'manage_content_types_create', 'summary' => 'Create a new content type (generates DB table)', 'tags' => ['Management — Content Types'], 'security' => [['BearerAuth' => []]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string'], 'display_name' => ['type' => 'string'], 'fields' => ['type' => 'array', 'items' => ['type' => 'object']]], 'required' => ['handle', 'display_name']]]]], 'responses' => ['201' => ['description' => 'Created'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '422' => ['description' => 'Validation error']]],
        ];
        $paths['/api/v1/manage/content-types/{handle}'] = [
            'get' => ['operationId' => 'manage_content_types_show', 'summary' => 'Get a content type', 'tags' => ['Management — Content Types'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            'put' => ['operationId' => 'manage_content_types_update', 'summary' => 'Update a content type schema', 'tags' => ['Management — Content Types'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found'], '422' => ['description' => 'Validation error']]],
        ];

        // ── Settings ───────────────────────────────────────────────────────────
        $paths['/api/v1/manage/settings'] = [
            'get' => ['operationId' => 'manage_settings_show', 'summary' => 'Get settings (secrets masked)', 'tags' => ['Management — Settings'], 'security' => [['BearerAuth' => []]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
            'put' => ['operationId' => 'manage_settings_update', 'summary' => 'Update settings', 'tags' => ['Management — Settings'], 'security' => [['BearerAuth' => []]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['group' => ['type' => 'string'], 'values' => ['type' => 'object']], 'required' => ['group', 'values']]]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
        ];

        // ── Users ──────────────────────────────────────────────────────────────
        $paths['/api/v1/manage/users'] = [
            'get' => ['operationId' => 'manage_users_list', 'summary' => 'List users', 'tags' => ['Management — Users'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
        ];
        $paths['/api/v1/manage/users/{user}'] = [
            'get' => ['operationId' => 'manage_users_show', 'summary' => 'Get a user', 'tags' => ['Management — Users'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            'put' => ['operationId' => 'manage_users_update', 'summary' => 'Update a user', 'tags' => ['Management — Users'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];
        $paths['/api/v1/manage/users/{user}/roles'] = [
            'post' => ['operationId' => 'manage_users_assign_role', 'summary' => 'Assign a role to a user', 'tags' => ['Management — Users'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['role' => ['type' => 'string']], 'required' => ['role']]]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];

        // ── Webhooks ───────────────────────────────────────────────────────────
        $paths['/api/v1/manage/webhooks'] = [
            'get' => ['operationId' => 'manage_webhooks_list', 'summary' => 'List webhook subscriptions', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden']]],
            'post' => ['operationId' => 'manage_webhooks_create', 'summary' => 'Create a webhook subscription', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'format' => 'uri'], 'events' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => ['type' => 'string', 'nullable' => true]], 'required' => ['url', 'events']]]]], 'responses' => ['201' => ['description' => 'Created'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '422' => ['description' => 'Validation error']]],
        ];
        $paths['/api/v1/manage/webhooks/{webhook}'] = [
            'get' => ['operationId' => 'manage_webhooks_show', 'summary' => 'Get a webhook subscription', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'webhook', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            'put' => ['operationId' => 'manage_webhooks_update', 'summary' => 'Update a webhook subscription', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'webhook', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
            'delete' => ['operationId' => 'manage_webhooks_delete', 'summary' => 'Delete a webhook subscription', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'webhook', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['204' => ['description' => 'Deleted'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];
        $paths['/api/v1/manage/webhooks/{webhook}/deliveries'] = [
            'get' => ['operationId' => 'manage_webhook_deliveries_list', 'summary' => 'List deliveries for a webhook', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'webhook', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]]], 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found']]],
        ];
        $paths['/api/v1/manage/webhooks/{webhook}/deliveries/{delivery}/retry'] = [
            'post' => ['operationId' => 'manage_webhook_delivery_retry', 'summary' => 'Retry a failed webhook delivery', 'tags' => ['Management — Webhooks'], 'security' => [['BearerAuth' => []]], 'parameters' => [['name' => 'webhook', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'delivery', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'Re-queued'], '401' => ['description' => 'Unauthorized'], '403' => ['description' => 'Forbidden'], '404' => ['description' => 'Not found'], '422' => ['description' => 'Already delivered']]],
        ];

        return [
            'paths' => $paths,
            'components' => [
                'schemas' => [
                    'WebhookSubscription' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'secret' => ['type' => 'string'],
                            'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'boolean'],
                            'description' => ['type' => 'string', 'nullable' => true],
                            'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ],
                    'WebhookDelivery' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'subscription_id' => ['type' => 'string'],
                            'event' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['pending', 'delivered', 'failed', 'dead']],
                            'attempts' => ['type' => 'integer'],
                            'last_attempt_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            'response_code' => ['type' => 'integer', 'nullable' => true],
                            'response_body' => ['type' => 'string', 'nullable' => true],
                            'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        $schemas = [];

        foreach ($this->visibleTypes() as $handle => $type) {
            $basePath = '/api/v1/content/'.$handle;

            $paths[$basePath] = [
                'get' => [
                    'operationId' => 'list_'.$handle,
                    'summary' => 'List '.$type->displayName.' entries',
                    'security' => [['BearerAuth' => []]],
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
            ];

            $paths[$basePath.'/{id}'] = [
                'get' => [
                    'operationId' => 'get_'.$handle,
                    'summary' => 'Get a single '.$type->displayName.' entry',
                    'security' => [['BearerAuth' => []]],
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
            ];

            $paths[$basePath.'/{id}/preview-token'] = [
                'post' => [
                    'operationId' => 'mint_preview_token_'.$handle,
                    'summary' => 'Mint a short-lived preview token for a '.$type->displayName.' entry',
                    'security' => [['BearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['ttl_seconds' => ['type' => 'integer', 'default' => 3600]]]]]],
                    'responses' => [
                        '200' => ['description' => 'Token minted'],
                        '401' => ['description' => 'Unauthorized — management token required'],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
            ];

            // Schema component for this type
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

            $schemas[$handle] = ['type' => 'object', 'properties' => $properties];
            $schemas[$handle.'List'] = [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$handle]],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                    'included' => ['type' => 'object'],
                ],
            ];
        }

        $schemas['PaginationMeta'] = [
            'type' => 'object',
            'properties' => [
                'next_cursor' => ['type' => 'string', 'nullable' => true],
                'has_more' => ['type' => 'boolean'],
                'per_page' => ['type' => 'integer'],
            ],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Magna CMS Delivery API', 'version' => '1'],
            'components' => [
                'securitySchemes' => ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => $schemas,
            ],
            'paths' => $paths,
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

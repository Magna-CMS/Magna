<?php

declare(strict_types=1);

namespace Magna\Delivery\OpenApi;

use Magna\Content\ContentType;

/**
 * The Management API's contribution to the OpenAPI document, one builder
 * method per resource. Extracted from a 147-line OpenApiGenerator method
 * whose 700-character lines hid a public surface inside noise (W3-5); the
 * emitted document is pinned byte-for-byte by OpenApiSnapshotTest, so the
 * decomposition is provably invisible to consumers.
 */
final class ManagementPaths
{
    private const TAG_ENTRIES = 'Management — Entries';

    private const TAG_MEDIA = 'Management — Media';

    private const TAG_CONTENT_TYPES = 'Management — Content Types';

    private const TAG_SETTINGS = 'Management — Settings';

    private const TAG_USERS = 'Management — Users';

    private const TAG_WEBHOOKS = 'Management — Webhooks';

    /**
     * Entry CRUD plus the publish/draft/revision lifecycle, for one
     * registered content type.
     *
     * @return array<string, mixed>
     */
    public function entryPaths(string $handle, ContentType $type): array
    {
        $base = '/api/v1/manage/entries/'.$handle;
        $entryPath = $base.'/{id}';
        $idParam = [ResponseShapes::pathParam('id')];

        return [
            $base => [
                'get' => ResponseShapes::operation(
                    'manage_list_'.$handle,
                    'List '.$type->displayName.' entries (management)',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK']),
                    parameters: [ResponseShapes::perPageParam()],
                ),
                'post' => ResponseShapes::operation(
                    'manage_create_'.$handle,
                    'Create a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['201' => 'Created'], ['422' => 'Validation error']),
                    requestBody: ResponseShapes::jsonBody(['type' => 'object']),
                ),
            ],
            $entryPath => [
                'get' => ResponseShapes::operation(
                    'manage_get_'.$handle,
                    'Get a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: $idParam,
                ),
                'put' => ResponseShapes::operation(
                    'manage_update_'.$handle,
                    'Update a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found', '422' => 'Validation error']),
                    parameters: $idParam,
                    requestBody: ResponseShapes::jsonBody(['type' => 'object']),
                ),
                'delete' => ResponseShapes::operation(
                    'manage_delete_'.$handle,
                    'Delete a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['204' => 'Deleted'], ['404' => 'Not found']),
                    parameters: $idParam,
                ),
            ],
            $entryPath.'/publish' => [
                'post' => ResponseShapes::operation(
                    'manage_publish_'.$handle,
                    'Publish a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: $idParam,
                    requestBody: ResponseShapes::optionalJsonBody([
                        'type' => 'object',
                        'properties' => ['publish_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]],
                    ]),
                ),
            ],
            $entryPath.'/unpublish' => [
                'post' => ResponseShapes::operation(
                    'manage_unpublish_'.$handle,
                    'Unpublish a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found', '422' => 'Entry not published']),
                    parameters: $idParam,
                ),
            ],
            $entryPath.'/draft' => [
                'post' => ResponseShapes::operation(
                    'manage_draft_'.$handle,
                    'Create a draft of a published '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['201' => 'Draft created'], ['404' => 'Not found', '422' => 'Not a published entry']),
                    parameters: $idParam,
                ),
            ],
            $entryPath.'/revisions' => [
                'get' => ResponseShapes::operation(
                    'manage_revisions_'.$handle,
                    'List revisions for a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: $idParam,
                ),
            ],
            $entryPath.'/revisions/{revision}/restore' => [
                'post' => ResponseShapes::operation(
                    'manage_restore_'.$handle,
                    'Restore a revision of a '.$type->displayName.' entry',
                    self::TAG_ENTRIES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('id'), ResponseShapes::pathParam('revision')],
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaPaths(): array
    {
        return [
            '/api/v1/manage/media' => [
                'post' => ResponseShapes::operation(
                    'manage_media_upload',
                    'Upload a media file',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['201' => 'Uploaded'], ['422' => 'Validation error']),
                    requestBody: [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'file' => ['type' => 'string', 'format' => 'binary'],
                                        'alt' => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                        'folder_id' => ['type' => 'string'],
                                    ],
                                    'required' => ['file'],
                                ],
                            ],
                        ],
                    ],
                ),
            ],
            '/api/v1/manage/media/{media}' => [
                'get' => ResponseShapes::operation(
                    'manage_media_show',
                    'Get a media item',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('media')],
                ),
                'delete' => ResponseShapes::operation(
                    'manage_media_delete',
                    'Delete a media item',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['204' => 'Deleted'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('media')],
                ),
            ],
            '/api/v1/manage/media/folders' => [
                'get' => ResponseShapes::operation(
                    'manage_folders_list',
                    'List media folders',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['200' => 'OK']),
                ),
                'post' => ResponseShapes::operation(
                    'manage_folders_create',
                    'Create a media folder',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['201' => 'Created'], ['422' => 'Validation error']),
                    requestBody: ResponseShapes::jsonBody([
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'parent_id' => ['type' => 'string', 'nullable' => true],
                        ],
                        'required' => ['name'],
                    ]),
                ),
            ],
            '/api/v1/manage/media/folders/{folder}' => [
                'delete' => ResponseShapes::operation(
                    'manage_folders_delete',
                    'Delete a media folder',
                    self::TAG_MEDIA,
                    ResponseShapes::guarded(['204' => 'Deleted'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('folder')],
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentTypePaths(): array
    {
        return [
            '/api/v1/manage/content-types' => [
                'get' => ResponseShapes::operation(
                    'manage_content_types_list',
                    'List registered content types',
                    self::TAG_CONTENT_TYPES,
                    ResponseShapes::guarded(['200' => 'OK']),
                ),
                'post' => ResponseShapes::operation(
                    'manage_content_types_create',
                    'Create a new content type (generates DB table)',
                    self::TAG_CONTENT_TYPES,
                    ResponseShapes::guarded(['201' => 'Created'], ['422' => 'Validation error']),
                    requestBody: ResponseShapes::jsonBody([
                        'type' => 'object',
                        'properties' => [
                            'handle' => ['type' => 'string'],
                            'display_name' => ['type' => 'string'],
                            'fields' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                        'required' => ['handle', 'display_name'],
                    ]),
                ),
            ],
            '/api/v1/manage/content-types/{handle}' => [
                'get' => ResponseShapes::operation(
                    'manage_content_types_show',
                    'Get a content type',
                    self::TAG_CONTENT_TYPES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('handle')],
                ),
                'put' => ResponseShapes::operation(
                    'manage_content_types_update',
                    'Update a content type schema',
                    self::TAG_CONTENT_TYPES,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found', '422' => 'Validation error']),
                    parameters: [ResponseShapes::pathParam('handle')],
                    requestBody: ResponseShapes::jsonBody(['type' => 'object']),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPaths(): array
    {
        return [
            '/api/v1/manage/settings' => [
                'get' => ResponseShapes::operation(
                    'manage_settings_show',
                    'Get settings (secrets masked)',
                    self::TAG_SETTINGS,
                    ResponseShapes::guarded(['200' => 'OK']),
                ),
                'put' => ResponseShapes::operation(
                    'manage_settings_update',
                    'Update settings',
                    self::TAG_SETTINGS,
                    ResponseShapes::guarded(['200' => 'OK']),
                    requestBody: ResponseShapes::jsonBody([
                        'type' => 'object',
                        'properties' => [
                            'group' => ['type' => 'string'],
                            'values' => ['type' => 'object'],
                        ],
                        'required' => ['group', 'values'],
                    ]),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function userPaths(): array
    {
        return [
            '/api/v1/manage/users' => [
                'get' => ResponseShapes::operation(
                    'manage_users_list',
                    'List users',
                    self::TAG_USERS,
                    ResponseShapes::guarded(['200' => 'OK']),
                    parameters: [ResponseShapes::perPageParam()],
                ),
            ],
            '/api/v1/manage/users/{user}' => [
                'get' => ResponseShapes::operation(
                    'manage_users_show',
                    'Get a user',
                    self::TAG_USERS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('user')],
                ),
                'put' => ResponseShapes::operation(
                    'manage_users_update',
                    'Update a user',
                    self::TAG_USERS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('user')],
                    requestBody: ResponseShapes::jsonBody(['type' => 'object']),
                ),
            ],
            '/api/v1/manage/users/{user}/roles' => [
                'post' => ResponseShapes::operation(
                    'manage_users_assign_role',
                    'Assign a role to a user',
                    self::TAG_USERS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('user')],
                    requestBody: ResponseShapes::jsonBody([
                        'type' => 'object',
                        'properties' => ['role' => ['type' => 'string']],
                        'required' => ['role'],
                    ]),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookPaths(): array
    {
        return [
            '/api/v1/manage/webhooks' => [
                'get' => ResponseShapes::operation(
                    'manage_webhooks_list',
                    'List webhook subscriptions',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['200' => 'OK']),
                ),
                'post' => ResponseShapes::operation(
                    'manage_webhooks_create',
                    'Create a webhook subscription',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['201' => 'Created'], ['422' => 'Validation error']),
                    requestBody: ResponseShapes::jsonBody([
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'description' => ['type' => 'string', 'nullable' => true],
                        ],
                        'required' => ['url', 'events'],
                    ]),
                ),
            ],
            '/api/v1/manage/webhooks/{webhook}' => [
                'get' => ResponseShapes::operation(
                    'manage_webhooks_show',
                    'Get a webhook subscription',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('webhook')],
                ),
                'put' => ResponseShapes::operation(
                    'manage_webhooks_update',
                    'Update a webhook subscription',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('webhook')],
                    requestBody: ResponseShapes::jsonBody(['type' => 'object']),
                ),
                'delete' => ResponseShapes::operation(
                    'manage_webhooks_delete',
                    'Delete a webhook subscription',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['204' => 'Deleted'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('webhook')],
                ),
            ],
            '/api/v1/manage/webhooks/{webhook}/deliveries' => [
                'get' => ResponseShapes::operation(
                    'manage_webhook_deliveries_list',
                    'List deliveries for a webhook',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['200' => 'OK'], ['404' => 'Not found']),
                    parameters: [ResponseShapes::pathParam('webhook'), ResponseShapes::perPageParam()],
                ),
            ],
            '/api/v1/manage/webhooks/{webhook}/deliveries/{delivery}/retry' => [
                'post' => ResponseShapes::operation(
                    'manage_webhook_delivery_retry',
                    'Retry a failed webhook delivery',
                    self::TAG_WEBHOOKS,
                    ResponseShapes::guarded(['200' => 'Re-queued'], ['404' => 'Not found', '422' => 'Already delivered']),
                    parameters: [ResponseShapes::pathParam('webhook'), ResponseShapes::pathParam('delivery')],
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schemas(): array
    {
        return [
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
        ];
    }
}

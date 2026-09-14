<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Magna\Content\ContentType;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\OpenApi\DeliveryPaths;
use Magna\Delivery\OpenApi\ManagementPaths;

/**
 * Generates an OpenAPI 3.1 specification from the registered content types.
 * Auto-updated whenever SchemaRegistry::register() is called.
 *
 * Orchestrator only (W3-5): which types are visible to the caller and how
 * the document is assembled live here; what each API contributes lives in
 * the OpenApi\DeliveryPaths and OpenApi\ManagementPaths builders. The
 * emitted document is a public surface, pinned byte-for-byte by
 * OpenApiSnapshotTest.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly DeliveryPaths $delivery = new DeliveryPaths,
        private readonly ManagementPaths $management = new ManagementPaths,
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

        foreach ($this->visibleTypes() as $handle => $type) {
            $paths += $this->management->entryPaths($handle, $type);
        }

        $paths += $this->management->mediaPaths();
        $paths += $this->management->contentTypePaths();
        $paths += $this->management->settingsPaths();
        $paths += $this->management->userPaths();
        $paths += $this->management->webhookPaths();

        return [
            'paths' => $paths,
            'components' => [
                'schemas' => $this->management->schemas(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        $schemas = [];

        foreach ($this->visibleTypes() as $handle => $type) {
            $paths += $this->delivery->typePaths($handle, $type);
            $schemas += $this->delivery->typeSchemas($handle, $type);
        }

        $schemas['PaginationMeta'] = $this->delivery->paginationMetaSchema();

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
}

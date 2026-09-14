<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Magna\Content\ContentType;

/**
 * Everything a single-entry Delivery request has established by the time
 * the entry itself is resolved: the request context (type, surrogate keys,
 * ETag cache key), the caller's addressing (route type handle + id), and
 * the preview/caching stance. Replaces the ten-parameter signature
 * ContentSingleController::resolveEntryResponse() used to thread these
 * through (W3-7).
 */
final readonly class SingleEntryLookup
{
    public function __construct(
        public ContentType $contentType,
        public SurrogateKeyCollector $keys,
        public string $cacheKey,
        public string $type,
        public string $id,
        public string $bodyCacheKey,
        public bool $preview,
        public string $previewToken,
        public bool $isPublic,
    ) {}

    /** A draft may be served only when a preview token was presented. */
    public function allowsDrafts(): bool
    {
        return $this->preview && $this->previewToken !== '';
    }
}

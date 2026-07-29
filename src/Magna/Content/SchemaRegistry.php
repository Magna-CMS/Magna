<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Magna\Content\Models\ContentTypeRecord;

class SchemaRegistry
{
    /** Invalidated by ContentTypeRecord::booted() on every write (saved/deleted). */
    public const CONTENT_TYPES_CACHE_KEY = 'magna:content_types:rows';

    /** @var array<string, ContentType> */
    private array $types = [];

    /** @var list<string> Handles registered from the database on the last load. */
    private array $databaseHandles = [];

    /** @var list<callable(ContentType): void> */
    private array $onRegisterCallbacks = [];

    public function __construct(private readonly FieldTypeRegistry $fieldTypes) {}

    /**
     * Register a callback that fires every time a content type is registered.
     * Called immediately for types already registered at the time of subscription.
     *
     * @param  callable(ContentType): void  $callback
     */
    public function onTypeRegistered(callable $callback): void
    {
        $this->onRegisterCallbacks[] = $callback;

        foreach ($this->types as $type) {
            $callback($type);
        }
    }

    public function register(ContentType $type): void
    {
        $this->types[$type->handle] = $type;

        foreach ($this->onRegisterCallbacks as $callback) {
            $callback($type);
        }
    }

    /**
     * Load all JSON schema files from a directory.
     */
    public function loadFromDirectory(string $path): void
    {
        foreach (glob($path.'/*.json') ?: [] as $file) {
            $type = ContentType::fromFile($file, $this->fieldTypes);
            $this->register($type);
        }
    }

    /**
     * Load database-defined content types from the content_types table.
     */
    public function loadFromDatabase(): void
    {
        if (! Schema::hasTable('content_types')) {
            return;
        }

        // Stage 12/13: this previously re-queried and re-parsed every
        // registered content type's schema JSON on every single request
        // (including every Delivery API hit), for data that only changes
        // when an admin edits a content type. Cached, with invalidation
        // wired into ContentTypeRecord::booted() on every write path — see
        // that model for why invalidation lives there rather than being
        // duplicated across every caller. Caches only the raw schema
        // arrays (not parsed ContentType/Field objects, which reference
        // FieldTypeRegistry-resolved FieldType instances that aren't safe
        // to serialize into a cache store) — parsing raw arrays back into
        // ContentType objects is cheap; the DB round-trip was the cost.
        /** @var list<array{handle: string, schema: array<string, mixed>}> $rows */
        $rows = Cache::rememberForever(self::CONTENT_TYPES_CACHE_KEY, function (): array {
            /** @var Collection<int, ContentTypeRecord> $records */
            $records = ContentTypeRecord::query()->get(['handle', 'schema']);

            return $records->map(fn (ContentTypeRecord $record): array => [
                'handle' => $record->handle,
                'schema' => $record->schema,
            ])->all();
        });

        $loaded = [];
        foreach ($rows as $row) {
            $type = ContentType::fromArray($row['schema'], $this->fieldTypes);
            $this->register($type);
            $loaded[] = $type->handle;
        }
        $this->databaseHandles = $loaded;
    }

    /**
     * Re-sync the database-defined content types into the registry: forget any
     * previously DB-loaded type that no longer exists, then reload the current
     * set (cache-backed via loadFromDatabase()).
     *
     * Under a long-lived Octane worker this registry is a persistent singleton,
     * so the content types loaded at worker boot would otherwise be frozen — a
     * type created, edited, or deleted through the admin builder OR by enabling/
     * disabling a plugin (both write the content_types table) would stay
     * invisible on that worker until it recycles. Calling this at the start of
     * each request keeps every worker consistent with the shared cache/DB. It
     * is cheap: the row set is served from the same cache the delivery API uses
     * and is only rebuilt when a content type actually changes.
     *
     * Under php-fpm the application boots fresh per request, so the registry is
     * already current and callers simply need not invoke this.
     */
    public function refreshDatabaseTypes(): void
    {
        foreach ($this->databaseHandles as $handle) {
            unset($this->types[$handle]);
        }
        $this->databaseHandles = [];

        $this->loadFromDatabase();
    }

    /**
     * Remove a content type from the in-memory registry. Used when a plugin
     * that owns the type is disabled or uninstalled, so its navigation and
     * resources disappear within the same request.
     */
    public function forget(string $handle): void
    {
        unset($this->types[$handle]);
    }

    public function get(string $handle): ?ContentType
    {
        return $this->types[$handle] ?? null;
    }

    public function has(string $handle): bool
    {
        return isset($this->types[$handle]);
    }

    /** @return array<string, ContentType> */
    public function all(): array
    {
        return $this->types;
    }

    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }
}

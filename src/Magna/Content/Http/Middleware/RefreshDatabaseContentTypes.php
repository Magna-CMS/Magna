<?php

declare(strict_types=1);

namespace Magna\Content\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Magna\Content\SchemaRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the SchemaRegistry's database-defined content types current on every
 * request when running under Octane.
 *
 * The registry is a container singleton. Under php-fpm each request boots the
 * application afresh, so the registry is rebuilt (and loadFromDatabase() runs)
 * per request and this middleware is a deliberate no-op. Under Octane the same
 * singleton is reused across every request a worker serves, so without this a
 * content type created/edited/deleted after the worker booted — via the admin
 * ContentTypeBuilder or by enabling/disabling a plugin, both of which write the
 * content_types table — would remain stale on that worker until it recycles,
 * producing worker-to-worker inconsistency (a new type 404ing on some workers,
 * a deleted one still served on others).
 *
 * The refresh is cache-backed (the content_types row set is cached and only
 * rebuilt on an actual change), so the per-request cost under Octane is a
 * single cache read when nothing has changed.
 */
final class RefreshDatabaseContentTypes
{
    public function __construct(private readonly SchemaRegistry $schema) {}

    public function handle(Request $request, Closure $next): Response
    {
        // LARAVEL_OCTANE is set on the worker by every octane:start command.
        // Only pay the refresh when a worker is actually being reused.
        if (filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN)) {
            $this->schema->refreshDatabaseTypes();
        }

        return $next($request);
    }
}

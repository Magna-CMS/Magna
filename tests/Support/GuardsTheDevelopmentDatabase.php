<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The last check, made after the framework has resolved a connection.
 *
 * tests/bootstrap.php sets the test database before Laravel exists and refuses
 * to run at all under a cached configuration. This asks the only question that
 * really matters, at the only moment it can be answered for certain: what file
 * is this connection actually pointed at?
 *
 * It exists because the failure on 13 August 2026 was silent. Every setting
 * said `:memory:`; the connection was to the development database, and nothing
 * looked wrong until the tables were gone. A test suite that can answer this
 * question and does not ask it is one destructive command away from the same
 * morning.
 */
trait GuardsTheDevelopmentDatabase
{
    protected function assertNotUsingTheDevelopmentDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        // In-memory is the intended answer and needs no further thought.
        if ($database === ':memory:' || $database === '') {
            return;
        }

        $resolved = realpath($database);

        if ($resolved === false) {
            // A path that does not exist yet cannot be the development
            // database, which does. A dedicated file database is allowed.
            return;
        }

        $forbidden = realpath(base_path('database/database.sqlite'));

        if ($forbidden !== false && $resolved === $forbidden) {
            throw new RuntimeException(
                'Tests are pointed at the development database ('.$database.'). '
                .'Refusing to continue: RefreshDatabase would drop every table in it. '
                .'Check for a cached configuration (php artisan config:clear) and that '
                .'.env.testing sets DB_DATABASE=:memory:.'
            );
        }
    }
}

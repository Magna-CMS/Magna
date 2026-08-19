<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

/**
 * Enabling a plugin must not create tables inside a transaction.
 *
 * MySQL commits implicitly on DDL. A `CREATE TABLE` inside the enable
 * transaction therefore ends it, and the commit that follows fails with
 * "There is no active transaction" — which the panel reports as
 * "Failed to enable plugin", on an enable that has in fact written
 * everything. Seen on a production install enabling a plugin with four
 * content types.
 *
 * SQLite, which the suite runs on, has no such behaviour: the bug is
 * invisible to any assertion about the *outcome*. So this asserts the
 * structural property instead — that the transaction depth is zero at the
 * moment each content-type table is created. That holds on every driver, and
 * it is exactly what was violated.
 */
it('creates content-type tables outside any transaction', function (): void {
    skipWithoutDevPlugin('magna/pages');

    // The suite itself runs each test inside a transaction, so "no transaction"
    // means "no deeper than the harness already was" rather than zero.
    $baseline = DB::transactionLevel();

    $depths = [];

    DB::listen(function ($query) use (&$depths): void {
        if (str_contains(strtolower($query->sql), 'create table')
            && str_contains($query->sql, 'magna_entries_')) {
            $depths[] = DB::transactionLevel();
        }
    });

    app(PluginManager::class)->enable('magna/pages');

    expect($depths)->not->toBeEmpty('No content-type table was created, so this proves nothing.');

    foreach ($depths as $depth) {
        expect($depth)->toBe($baseline);
    }
});

it('still records the plugin and its content types', function (): void {
    skipWithoutDevPlugin('magna/pages');

    app(PluginManager::class)->enable('magna/pages');

    // The transaction split must not cost the outcome it was protecting: the
    // record and the content_types rows both have to be there afterwards.
    expect(PluginRecord::query()->where('name', 'magna/pages')->where('enabled', true)->exists())->toBeTrue()
        ->and(DB::table('content_types')->count())->toBeGreaterThan(0);
});

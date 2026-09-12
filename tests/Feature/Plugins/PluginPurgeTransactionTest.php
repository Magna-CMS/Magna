<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: `magna:plugin:uninstall --purge` did its work and then reported
 * failure on MySQL.
 *
 * The drops ran inside the transaction that wrapped the row cleanup. MySQL
 * commits an open transaction the instant it sees DDL, so by the time the
 * closure returned there was nothing left to commit and the uninstall ended
 * with "There is no active transaction" — after the tables were already gone.
 * SQLite happily runs DDL inside a transaction, which is why the suite never
 * saw it and a real deployment did.
 *
 * The assertion is on the invariant rather than the symptom: no DROP TABLE may
 * be issued while a transaction is open. That holds on every driver, including
 * the one the suite runs on, so it fails here if the ordering is ever undone.
 */

/** @return array<string, mixed> */
function purgeManifest(): array
{
    return [
        'name' => 'acme/ledger',
        'displayName' => 'Ledger',
        'description' => 'Declares tables to drop on purge.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'entry' => 'Acme\Ledger\LedgerPlugin',
        'provides' => ['contentTypes' => ['ledger_entry']],
        'permissions' => [],
        'uninstall' => [
            // Parent deliberately listed before the child that references it:
            // the order MySQL refuses unless the purge drops the tables'
            // foreign keys first. SQLite doesn't enforce drop order, so this
            // only bites (and is only proven) on the MySQL/Postgres CI legs.
            'tables' => ['acme_ledger_books', 'acme_ledger_lines'],
            'contentTypes' => ['ledger_entry'],
        ],
    ];
}

function createLedgerPlugin(): PluginRecord
{
    Schema::create('acme_ledger_books', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('acme_ledger_lines', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('book_id')->constrained('acme_ledger_books');
    });

    Schema::create('magna_entries_ledger_entry', function (Blueprint $table): void {
        $table->id();
    });

    return PluginRecord::create([
        'name' => 'acme/ledger',
        'display_name' => 'Ledger',
        'version' => '1.0.0',
        'enabled' => false,
        'base_path' => base_path('plugins-dev/acme/ledger'),
        'manifest' => purgeManifest(),
    ]);
}

/** @return list<string> */
function dropsIssuedInsideATransaction(Closure $work): array
{
    $offenders = [];

    // RefreshDatabase holds a transaction open around the whole test, so the
    // question is not whether a transaction exists but whether the code under
    // test opened one of its own.
    $baseline = DB::transactionLevel();

    DB::listen(function ($query) use (&$offenders, $baseline): void {
        if (str_contains(strtolower($query->sql), 'drop table') && DB::transactionLevel() > $baseline) {
            $offenders[] = $query->sql;
        }
    });

    $work();

    return $offenders;
}

it('drops a purged plugin\'s tables outside the transaction', function (): void {
    createLedgerPlugin();

    $offenders = dropsIssuedInsideATransaction(
        fn () => app(PluginManager::class)->uninstall('acme/ledger', purge: true),
    );

    expect($offenders)->toBe([]);
});

it('still removes everything the purge is responsible for', function (): void {
    createLedgerPlugin();

    app(PluginManager::class)->uninstall('acme/ledger', purge: true);

    expect(PluginRecord::query()->where('name', 'acme/ledger')->exists())->toBeFalse()
        ->and(Schema::hasTable('acme_ledger_books'))->toBeFalse()
        ->and(Schema::hasTable('acme_ledger_lines'))->toBeFalse()
        ->and(Schema::hasTable('magna_entries_ledger_entry'))->toBeFalse();
});

it('refuses to drop a table another installed plugin claims', function (): void {
    createLedgerPlugin();

    // A second plugin whose manifest claims one of the ledger's tables. A
    // tampered or careless uninstall.tables list must not be able to take a
    // different plugin's data with it.
    PluginRecord::create([
        'name' => 'acme/reports',
        'display_name' => 'Reports',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => base_path('plugins-dev/acme/reports'),
        'manifest' => [
            'name' => 'acme/reports',
            'entry' => 'Acme\Reports\ReportsPlugin',
            'uninstall' => ['tables' => ['acme_ledger_books']],
        ],
    ]);

    app(PluginManager::class)->uninstall('acme/ledger', purge: true);

    // The claimed table survives; everything unclaimed still goes. The child
    // table can only fall if the purge dropped its foreign key first — its
    // parent is the table that stays.
    expect(Schema::hasTable('acme_ledger_books'))->toBeTrue()
        ->and(Schema::hasTable('acme_ledger_lines'))->toBeFalse()
        ->and(Schema::hasTable('magna_entries_ledger_entry'))->toBeFalse();
});

it('leaves foreign key enforcement on for every other table', function (): void {
    // Regression guard for the SET FOREIGN_KEY_CHECKS=0 approach: that flag is
    // connection-scoped, so a purge that failed to switch it back on left the
    // whole connection — persistent, under Octane — running unprotected.
    // The current approach never touches it; this pins that invariant.
    Schema::create('fk_probe_parents', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('fk_probe_children', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->constrained('fk_probe_parents');
    });

    createLedgerPlugin();

    app(PluginManager::class)->uninstall('acme/ledger', purge: true);

    expect(fn () => DB::table('fk_probe_children')->insert(['parent_id' => 999_999]))
        ->toThrow(QueryException::class);

    Schema::drop('fk_probe_children');
    Schema::drop('fk_probe_parents');
});

it('keeps the data tables when the uninstall is not a purge', function (): void {
    createLedgerPlugin();

    app(PluginManager::class)->uninstall('acme/ledger');

    // Without --purge an uninstall is reversible: the record goes, the data
    // stays, and re-installing finds its tables intact.
    expect(PluginRecord::query()->where('name', 'acme/ledger')->exists())->toBeFalse()
        ->and(Schema::hasTable('acme_ledger_books'))->toBeTrue()
        ->and(Schema::hasTable('magna_entries_ledger_entry'))->toBeTrue();
});

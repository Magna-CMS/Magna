<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Delivery\CursorPaginator;
use Magna\Delivery\Exceptions\DeliveryException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const PAGE_HANDLE = 'pager_item';
const PAGE_TOTAL = 25;

function registerPagerType(): void
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => PAGE_HANDLE,
        'displayName' => 'Pager Item',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
            ['handle' => 'weight', 'type' => 'number'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

/**
 * Seed PAGE_TOTAL published entries with deliberately DUPLICATED sort values
 * (groups of 5 share weight/published_at; groups of 3 share created_at) so the
 * (sortColumn, id) tiebreaker is exercised on every non-id column.
 */
function seedPagerEntries(): void
{
    $table = 'magna_entries_'.PAGE_HANDLE;
    $base = now()->startOfDay();

    for ($i = 0; $i < PAGE_TOTAL; $i++) {
        $entry = Entry::type(PAGE_HANDLE)->create([
            'title' => 'Item '.$i,
            'slug' => 'item-'.$i,
            'weight' => intdiv($i, 5),                 // 0,0,0,0,0,1,1,... duplicates
            'status' => EntryStatus::Published,
            'locale' => '',
        ]);

        // Set exact timestamps via the query builder so Eloquent's automatic
        // timestamp touching doesn't overwrite the controlled duplicate groups.
        DB::table($table)->where('id', $entry->id)->update([
            'published_at' => $base->copy()->addMinutes(intdiv($i, 5))->toDateTimeString(),  // dup groups of 5
            'created_at' => $base->copy()->addSeconds(intdiv($i, 3))->toDateTimeString(),     // dup groups of 3
            'updated_at' => $base->copy()->addSeconds(PAGE_TOTAL - $i)->toDateTimeString(),   // strictly descending
        ]);
    }
}

/** Page fully through the published set, returning the id sequence seen. */
function pageThrough(string $sortColumn, bool $asc, int $perPage): array
{
    $paginator = app(CursorPaginator::class);
    $ids = [];
    $cursor = null;
    $guard = 0;

    do {
        $query = Entry::type(PAGE_HANDLE)->where('status', EntryStatus::Published->value);
        $result = $paginator->paginate($query, $perPage, $cursor, $sortColumn, $asc);
        foreach ($result->entries as $entry) {
            $ids[] = $entry->id;
        }
        $cursor = $result->nextCursor;
    } while ($result->hasMore && ++$guard < 1000);

    return $ids;
}

/** The order the delivery API is contracted to produce: (sortColumn, id) with a matching id tiebreaker. */
function expectedOrder(string $sortColumn, bool $asc): array
{
    return Entry::type(PAGE_HANDLE)->where('status', EntryStatus::Published->value)->get()
        ->sort(function (Entry $a, Entry $b) use ($sortColumn, $asc): int {
            $cmp = $a->getRawOriginal($sortColumn) <=> $b->getRawOriginal($sortColumn);
            if ($cmp === 0) {
                $cmp = $a->id <=> $b->id;
            }

            return $asc ? $cmp : -$cmp;
        })
        ->values()
        ->pluck('id')
        ->all();
}

beforeEach(function (): void {
    registerPagerType();
    seedPagerEntries();
});

// ── Every sort mode, both directions, across multiple pages ────────────────────

dataset('sortModes', [
    'id asc' => ['id', true],
    'id desc' => ['id', false],
    'published_at asc' => ['published_at', true],
    'published_at desc' => ['published_at', false],
    'created_at asc' => ['created_at', true],
    'created_at desc' => ['created_at', false],
    'updated_at asc' => ['updated_at', true],
    'updated_at desc' => ['updated_at', false],
    'weight asc (schema column, heavy duplicates)' => ['weight', true],
    'weight desc (schema column, heavy duplicates)' => ['weight', false],
]);

it('pages through in exact keyset order with no skips or duplicates', function (string $sortColumn, bool $asc): void {
    // perPage = 7 over 25 rows ⇒ 4 pages, so page boundaries land mid-duplicate-group.
    $seen = pageThrough($sortColumn, $asc, 7);

    expect($seen)->toHaveCount(PAGE_TOTAL);                    // nothing dropped
    expect(array_unique($seen))->toHaveCount(PAGE_TOTAL);      // nothing duplicated
    expect($seen)->toBe(expectedOrder($sortColumn, $asc));     // correct order + tiebreaker
})->with('sortModes');

// ── Boundary: perPage exactly equal to / larger than the row count ─────────────

it('reports no next cursor when the page holds every row', function (): void {
    $paginator = app(CursorPaginator::class);
    $query = Entry::type(PAGE_HANDLE)->where('status', EntryStatus::Published->value);

    $result = $paginator->paginate($query, PAGE_TOTAL, null, 'published_at', false);

    expect($result->hasMore)->toBeFalse();
    expect($result->nextCursor)->toBeNull();
    expect($result->entries)->toHaveCount(PAGE_TOTAL);
});

it('single-row pages still walk the whole set exactly once', function (): void {
    $seen = pageThrough('published_at', false, 1);

    expect($seen)->toHaveCount(PAGE_TOTAL);
    expect(array_unique($seen))->toHaveCount(PAGE_TOTAL);
    expect($seen)->toBe(expectedOrder('published_at', false));
});

// ── Token integrity ────────────────────────────────────────────────────────────

it('rejects a malformed cursor token', function (): void {
    $paginator = app(CursorPaginator::class);
    $query = Entry::type(PAGE_HANDLE)->where('status', EntryStatus::Published->value);

    expect(fn () => $paginator->paginate($query, 5, 'not-a-real-cursor!!', 'published_at', false))
        ->toThrow(DeliveryException::class);
});

it('id-sort cursor stays a bare ULID token (backward compatible)', function (): void {
    $paginator = app(CursorPaginator::class);
    $query = Entry::type(PAGE_HANDLE)->where('status', EntryStatus::Published->value);

    $result = $paginator->paginate($query, 5, null, 'id', false);

    // Bare ULID base64url decodes to exactly 26 ULID characters (no JSON envelope).
    $decoded = base64_decode(strtr((string) $result->nextCursor, '-_', '+/'), true);
    expect($decoded)->toMatch('/^[0-9A-Za-z]{26}$/');
});

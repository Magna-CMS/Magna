<?php

declare(strict_types=1);

/**
 * Revision workflow metadata (10-REVIEW-RESOLUTIONS §A4) + orphan cleanup
 * (review finding: hard-deleted entries left unrestorable snapshots behind
 * forever, and named milestones were evicted by routine save churn).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Content\ContentType;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerRevisionMetaType(): void
{
    $schemaRegistry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'revision_meta_page',
        'displayName' => 'Revision Meta Page',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));

    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);
}

it('stamps kind and document schema_version on update revisions', function (): void {
    registerRevisionMetaType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('revision_meta_page', [
        'title' => 'v1',
        'blocks_data' => ['schemaVersion' => '1.0', 'sections' => []],
    ], $user->id);

    $manager->update($entry, ['title' => 'v2'], $user->id);

    $revision = Revision::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($revision->kind)->toBe(Revision::KIND_SAVE)
        ->and($revision->label)->toBeNull()
        ->and($revision->schema_version)->toBe('1.0');
});

it('stamps restore_point on the pre-restore snapshot', function (): void {
    registerRevisionMetaType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('revision_meta_page', ['title' => 'original'], $user->id);
    $manager->update($entry, ['title' => 'changed'], $user->id);

    $firstRevision = Revision::query()->where('entry_id', $entry->getKey())->firstOrFail();
    $manager->restore($firstRevision->id, $user->id);

    $kinds = Revision::query()
        ->where('entry_id', $entry->getKey())
        ->pluck('kind');

    expect($kinds)->toContain(Revision::KIND_RESTORE_POINT);
});

it('deleting an entry removes its revisions', function (): void {
    registerRevisionMetaType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('revision_meta_page', ['title' => 'v1'], $user->id);
    $manager->update($entry, ['title' => 'v2'], $user->id);

    expect(Revision::query()->where('entry_id', $entry->getKey())->count())->toBeGreaterThan(0);

    $manager->delete($entry, $user->id);

    expect(Revision::query()->where('entry_id', $entry->getKey())->count())->toBe(0);
});

it('prunes unlabeled revisions but never labeled milestones', function (): void {
    registerRevisionMetaType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('revision_meta_page', ['title' => 'v0'], $user->id);

    for ($i = 1; $i <= 5; $i++) {
        $manager->update($entry, ['title' => "v{$i}"], $user->id);
    }

    // Name one mid-history revision as a milestone.
    $milestone = Revision::query()
        ->where('entry_id', $entry->getKey())
        ->orderBy('created_at')
        ->firstOrFail();
    DB::table('magna_revisions')->where('id', $milestone->id)->update(['label' => 'before redesign']);

    $this->artisan('magna:revisions:prune', ['--keep' => 2])->assertSuccessful();

    $remaining = Revision::query()->where('entry_id', $entry->getKey())->get();

    expect($remaining->whereNull('label')->count())->toBe(2)
        ->and($remaining->where('label', 'before redesign')->count())->toBe(1);
});

it('sweeps revisions orphaned by rows deleted outside EntryManager, but leaves unregistered types alone', function (): void {
    registerRevisionMetaType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('revision_meta_page', ['title' => 'v1'], $user->id);
    $manager->update($entry, ['title' => 'v2'], $user->id);

    // Simulate an out-of-band hard delete (raw SQL, plugin bug, manual DBA).
    DB::table('magna_entries_revision_meta_page')->where('id', $entry->getKey())->delete();

    // A revision belonging to a type that is not registered (disabled
    // plugin) must be tolerated, never swept.
    DB::table('magna_revisions')->insert([
        'id' => (string) Str::ulid(),
        'entry_type' => 'unregistered_plugin_type',
        'entry_id' => (string) Str::ulid(),
        'payload' => '{}',
        'kind' => 'save',
        'author_id' => null,
        'created_at' => now(),
    ]);

    $this->artisan('magna:revisions:prune')->assertSuccessful();

    expect(Revision::query()->where('entry_id', $entry->getKey())->count())->toBe(0)
        ->and(Revision::query()->where('entry_type', 'unregistered_plugin_type')->count())->toBe(1);
});

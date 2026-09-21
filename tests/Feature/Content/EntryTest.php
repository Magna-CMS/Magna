<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Magna\Auth\PermissionRegistry;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\Events\EntryCreated;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Content\SchemaValidator;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'article',
        'displayName' => 'Article',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'body', 'type' => 'textarea'],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
});

// ── Entry::type() ─────────────────────────────────────────────────────────────

it('Entry::type() returns a builder bound to the correct table', function (): void {
    $entry = Entry::type('article')->create([
        'title' => 'Test',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    expect($entry)->toBeInstanceOf(Entry::class)
        ->and($entry->getTable())->toBe('magna_entries_article')
        ->and($entry->getHandle())->toBe('article');
});

it('Entry::type() hydrates casts correctly', function (): void {
    Entry::type('article')->create([
        'title' => 'Cast Test',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $found = Entry::type('article')->first();

    expect($found->status)->toBe(EntryStatus::Draft);
});

// ── Mass-assignment fence ─────────────────────────────────────────────────────

/*
 * Entry declares $guarded = [] and is load-bearing about it: create()/
 * update() only ever fill what SchemaValidator::validate() returned, which
 * is Laravel's $validator->validated() — rule-covered keys only, with
 * status/author_id/published_at set explicitly afterwards. These two tests
 * pin both halves, so a refactor to `return $data` (or a fill from the raw
 * payload) cannot silently open every non-schema column to mass assignment.
 */
it('validate() returns only rule-covered keys', function (): void {
    $type = app(SchemaRegistry::class)->get('article');
    assert($type !== null);

    $validated = app(SchemaValidator::class)->validate($type, [
        'title' => 'Hello',
        'author_id' => 'attacker',
        'status' => 'published',
    ]);

    expect(array_keys($validated))->not->toContain('author_id')
        ->and(array_keys($validated))->not->toContain('status')
        ->and($validated['title'])->toBe('Hello');
});

it('create() ignores non-schema columns smuggled into the payload', function (): void {
    $entry = app(EntryManager::class)->create('article', [
        'title' => 'Hello',
        'author_id' => 'attacker',
        'status' => 'published',
    ]);

    expect($entry->author_id)->not->toBe('attacker')
        ->and($entry->status)->toBe(EntryStatus::Draft);
});

// ── Create ────────────────────────────────────────────────────────────────────

it('create() produces a draft for draftable types', function (): void {
    $manager = app(EntryManager::class);
    $entry = $manager->create('article', ['title' => 'Hello']);

    expect($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->published_at)->toBeNull();
});

it('create() fires EntryCreated', function (): void {
    Event::fake();

    app(EntryManager::class)->create('article', ['title' => 'Hello']);

    Event::assertDispatched(EntryCreated::class);
});

it('create() throws ValidationException when a required field is missing', function (): void {
    expect(fn () => app(EntryManager::class)->create('article', []))
        ->toThrow(ValidationException::class);
});

it('create() auto-generates slug from the configured source field', function (): void {
    $entry = app(EntryManager::class)->create('article', ['title' => 'Hello World']);

    expect($entry->slug)->toBe('hello-world');
});

it('create() generates a unique slug when one already exists', function (): void {
    $manager = app(EntryManager::class);
    $manager->create('article', ['title' => 'Duplicate']);
    $second = $manager->create('article', ['title' => 'Duplicate']);

    expect($second->slug)->toBe('duplicate-2');
});

it('create() immediately publishes non-draftable types', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'snippet',
        'displayName' => 'Snippet',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'content', 'type' => 'text', 'required' => true],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    Event::fake();
    $entry = app(EntryManager::class)->create('snippet', ['content' => 'Hello']);

    expect($entry->status)->toBe(EntryStatus::Published);
    Event::assertDispatched(EntryPublished::class);
});

// ── Full workflow ──────────────────────────────────────────────────────────────

it('full workflow: draft → publish → edit-as-draft → republish → revision count', function (): void {
    Event::fake();
    $manager = app(EntryManager::class);

    // 1. Create draft
    $draft = $manager->create('article', ['title' => 'Hello World']);
    expect($draft->status)->toBe(EntryStatus::Draft);
    expect((string) $draft->slug)->toBe('hello-world');
    Event::assertDispatched(EntryCreated::class);

    // 2. Publish → no revision yet (entry was a draft, no prior published state)
    $published = $manager->publish($draft);
    expect($published->status)->toBe(EntryStatus::Published);
    expect($published->published_at)->not->toBeNull();
    Event::assertDispatched(EntryPublished::class);
    expect(Revision::where('entry_type', 'article')->count())->toBe(0);

    // 3. Create draft-of-published
    $editDraft = $manager->createDraftOf($published);
    expect($editDraft->status)->toBe(EntryStatus::Draft);
    expect($editDraft->draft_of)->toBe($published->id);
    expect((string) $editDraft->getAttribute('title'))->toBe('Hello World');

    // 4. Update the draft
    $manager->update($editDraft, ['title' => 'Hello World Updated']);
    Event::assertDispatched(EntryUpdated::class);

    // 5. Republish (publish draft-of-published)
    Event::fake(); // reset to isolate the republish event
    $republished = $manager->publish($editDraft);
    expect($republished->id)->toBe($published->id);
    expect($republished->status)->toBe(EntryStatus::Published);
    Event::assertDispatched(EntryPublished::class);

    // 6. One revision was created (snapshot of published state before overwrite)
    expect(Revision::where('entry_type', 'article')->count())->toBe(1);

    // Draft is gone
    expect(Entry::type('article')->where('draft_of', $published->id)->count())->toBe(0);
});

// ── Scheduled publish ─────────────────────────────────────────────────────────

it('schedules an entry when published_at is in the future', function (): void {
    $manager = app(EntryManager::class);
    $entry = $manager->create('article', ['title' => 'Future Post']);

    $manager->publish($entry, now()->addHour());
    $entry->refresh();

    expect($entry->status)->toBe(EntryStatus::Scheduled);
});

it('magna:publish:scheduled promotes due entries to published', function (): void {
    $manager = app(EntryManager::class);
    $entry = $manager->create('article', ['title' => 'Future Post']);

    $manager->publish($entry, now()->addHour());
    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Scheduled);

    // Simulate time passing: move published_at to the past.
    DB::table('magna_entries_article')
        ->where('id', $entry->id)
        ->update(['published_at' => now()->subMinute()->toDateTimeString()]);

    Event::fake();
    Artisan::call('magna:publish:scheduled');

    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Published);
    Event::assertDispatched(EntryPublished::class);
});

// ── Revisions ─────────────────────────────────────────────────────────────────

it('restore() rolls back entry to a revision and snapshots the current state first', function (): void {
    $manager = app(EntryManager::class);

    // Create and publish
    $entry = $manager->create('article', ['title' => 'Original']);
    $manager->publish($entry);

    // Update directly on published (creates a revision)
    $manager->update($entry, ['title' => 'Updated'], null);

    // Two revisions: one from update (snapshot before update)
    // Actually only one: snapshot taken on update() since entry was published
    $revisions = Revision::where('entry_type', 'article')->orderBy('created_at')->get();
    expect($revisions)->toHaveCount(1);
    expect($revisions->first()->payload['title'])->toBe('Original');

    // Restore from the revision
    $restored = $manager->restore((string) $revisions->first()->id);
    expect((string) $restored->getAttribute('title'))->toBe('Original');

    // Restoring also created a new revision (snapshot of state before restore)
    expect(Revision::where('entry_type', 'article')->count())->toBe(2);
});

it('magna:revisions:prune keeps only the newest N revisions per entry', function (): void {
    $manager = app(EntryManager::class);
    $entry = $manager->create('article', ['title' => 'Rev Test']);
    $manager->publish($entry);

    // Create 5 revisions via direct updates on the published entry
    foreach (range(1, 5) as $i) {
        $manager->update($entry, ['title' => "Rev {$i}"]);
        $entry->refresh();
    }
    expect(Revision::where('entry_type', 'article')->count())->toBe(5);

    Artisan::call('magna:revisions:prune', ['--keep' => '3']);

    expect(Revision::where('entry_type', 'article')->count())->toBe(3);
});

// ── Unpublish ─────────────────────────────────────────────────────────────────

it('unpublish() archives the entry and fires EntryUnpublished', function (): void {
    Event::fake();
    $manager = app(EntryManager::class);

    $entry = $manager->create('article', ['title' => 'To Archive']);
    $manager->publish($entry);

    $archived = $manager->unpublish($entry);

    expect($archived->status)->toBe(EntryStatus::Archived);
    Event::assertDispatched(EntryUnpublished::class);
});

// ── Delete ────────────────────────────────────────────────────────────────────

it('delete() removes the entry and fires EntryDeleted', function (): void {
    Event::fake();
    $manager = app(EntryManager::class);

    $entry = $manager->create('article', ['title' => 'Doomed']);
    $id = $entry->id;

    $manager->delete($entry);

    expect(Entry::type('article')->find($id))->toBeNull();
    Event::assertDispatched(EntryDeleted::class);
});

// Stage 13 (S5-04): magna_relations can't carry a real foreign key (from_id/
// to_id point into dynamic per-type tables), so nothing previously cleaned up
// pivot rows referencing a deleted entry — they accumulated forever on both
// the "from" and "to" side.
it('delete() removes magna_relations rows referencing the entry on either side', function (): void {
    $manager = app(EntryManager::class);

    $a = $manager->create('article', ['title' => 'A']);
    $b = $manager->create('article', ['title' => 'B']);
    $unrelated = $manager->create('article', ['title' => 'C']);

    DB::table('magna_relations')->insert([
        [
            'id' => (string) Str::ulid(),
            'from_type' => 'article', 'from_id' => $a->id,
            'to_type' => 'article', 'to_id' => $b->id,
            'field' => 'related', 'sort' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'id' => (string) Str::ulid(),
            'from_type' => 'article', 'from_id' => $unrelated->id,
            'to_type' => 'article', 'to_id' => $a->id,
            'field' => 'related', 'sort' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'id' => (string) Str::ulid(),
            'from_type' => 'article', 'from_id' => $unrelated->id,
            'to_type' => 'article', 'to_id' => $b->id,
            'field' => 'related', 'sort' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    expect(DB::table('magna_relations')->count())->toBe(3);

    $manager->delete($a);

    // Rows where $a was either side are gone; the row between $unrelated and $b survives.
    expect(DB::table('magna_relations')->count())->toBe(1)
        ->and(DB::table('magna_relations')->where('from_id', $unrelated->id)->where('to_id', $b->id)->exists())->toBeTrue();
});

// ── Permissions ───────────────────────────────────────────────────────────────

it('content permissions are auto-registered when the type is registered', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach (['view', 'create', 'update', 'publish', 'delete'] as $action) {
        expect($registry->has("content.article.{$action}"))->toBeTrue();
    }
});

it('denies content.article.publish to users without the grant', function (): void {
    $user = User::factory()->create();

    expect($user->can('content.article.publish'))->toBeFalse();
});

it('allows content.article.publish to users with the grant', function (): void {
    $role = Role::factory()->create(['handle' => 'editor', 'name' => 'Editor', 'is_super_admin' => false]);
    $role->grant('content.article.publish');

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('content.article.publish'))->toBeTrue();
});
